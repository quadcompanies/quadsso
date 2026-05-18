<?php

namespace QuadCompanies\QuadSSO\Scim;

use ArieTimmerman\Laravel\SCIMServer\Attribute\Attribute;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Complex;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Constant;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Meta;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Schema as AttributeSchema;
use ArieTimmerman\Laravel\SCIMServer\SCIM\Schema;
use ArieTimmerman\Laravel\SCIMServer\SCIMConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function ArieTimmerman\Laravel\SCIMServer\complex;
use function ArieTimmerman\Laravel\SCIMServer\eloquent;

class QuadSSOScimConfig extends SCIMConfig
{
    /**
     * Get the User resource configuration for SCIM.
     */
    public function getUserConfig(): array
    {
        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $fieldMappings = config('quadsso.field_mappings', []);
        $allowCreation = config('quadsso.scim.allow_user_creation', true);
        $allowUpdates = config('quadsso.scim.allow_user_updates', true);
        $allowDeletion = config('quadsso.scim.allow_user_deletion', true);

        return [
            'class' => $userModel,
            'singular' => 'User',
            'withRelations' => [],
            'description' => 'User Account',

            // On POST (create), find an existing user by email instead of inserting a duplicate.
            // This handles manually-provisioned users that don't yet have a scim_external_id.
            'factory' => function () use ($userModel, $fieldMappings, $allowCreation) {
                if (!$allowCreation) {
                    throw new \Exception('User creation via SCIM is disabled.');
                }

                $emailField = $fieldMappings['email'] ?? 'email';
                $email = request()->input('userName')
                    ?? collect(request()->input('emails', []))->firstWhere('primary', true)['value']
                    ?? null;

                return $email
                    ? $userModel::firstOrNew([$emailField => $email])
                    : new $userModel();
            },

            'map' => $this->buildUserAttributeMap($fieldMappings),
        ];
    }

    /**
     * Build the SCIM attribute map for users.
     */
    protected function buildUserAttributeMap(array $fieldMappings): Complex
    {
        $statusField = config('quadsso.scim.user_status_field', 'status');
        $activeValue = config('quadsso.scim.active_status_value', 'active');
        $blockedValue = config('quadsso.scim.blocked_status_value', 'blocked');
        $invalidateSessions = config('quadsso.scim.invalidate_sessions_on_block', true);

        return complex()->withSubAttributes(

            // Required: schemas constant
            new class ('schemas', ['urn:ietf:params:scim:schemas:core:2.0:User']) extends Constant {
                public function replace($value, &$object, $path = null): void
                {
                    $this->dirty = true;
                }
            },

            // Required: id from primary key
            (new class ('id', null) extends Constant {
                protected function doRead(&$object, $attributes = []): string
                {
                    return (string) $object->id;
                }

                public function remove($value, &$object, $path = null): void
                {
                    // id is immutable — removal is a no-op
                }
            }),

            // Required: externalId — stores authentik's UUID
            eloquent('externalId', $fieldMappings['external_id'] ?? 'scim_external_id'),

            new Meta('Users'),

            (new AttributeSchema(Schema::SCHEMA_USER, true))->withSubAttributes(

                // userName maps to email (authentik sends email as userName)
                eloquent('userName', $fieldMappings['email'] ?? 'email')->ensure('required', 'email'),

                // active maps to our status field (active/blocked)
                (new class ($statusField, $activeValue, $blockedValue, $invalidateSessions) extends Attribute {
                    public function __construct(
                        protected string $statusField,
                        protected string $activeValue,
                        protected string $blockedValue,
                        protected bool $invalidateSessions
                    ) {
                        parent::__construct('active');
                    }

                    protected function doRead(&$object, $attributes = []): bool
                    {
                        return $object->{$this->statusField} === $this->activeValue;
                    }

                    public function add($value, Model &$object): void
                    {
                        $object->{$this->statusField} = $value ? $this->activeValue : $this->blockedValue;
                        $this->dirty = true;
                    }

                    public function replace($value, Model &$object, $path = null, $removeIfNotSet = false): void
                    {
                        if (config('quadsso.logging.scim_requests', false)) {
                            Log::debug('QuadSSO SCIM active replace', [
                                'user_id'    => $object->id,
                                'value'      => $value,
                                'value_type' => gettype($value),
                                'exists'     => $object->exists,
                                'path'       => $path,
                            ]);
                        }

                        $object->{$this->statusField} = $value ? $this->activeValue : $this->blockedValue;
                        $this->dirty = true;

                        // If blocking an existing user and session invalidation is enabled
                        if (!$value && $object->exists && $this->invalidateSessions) {
                            $rows = DB::table('users')
                                ->where('id', $object->id)
                                ->update([$this->statusField => $this->blockedValue]);

                            if (config('quadsso.logging.scim_requests', false)) {
                                Log::debug('QuadSSO SCIM blocked user', [
                                    'user_id' => $object->id,
                                    'rows_updated' => $rows
                                ]);
                            }

                            DB::table('sessions')->where('user_id', $object->id)->delete();
                        }
                    }
                })->ensure('boolean')->default(true),

                // name sub-attributes
                complex('name')->withSubAttributes(
                    eloquent('givenName', $fieldMappings['name_first'] ?? 'name_first')->ensure('nullable'),
                    eloquent('familyName', $fieldMappings['name_last'] ?? 'name_last')->ensure('nullable'),
                    eloquent('middleName', $fieldMappings['name_middle'] ?? 'name_middle')->ensure('nullable'),

                    // formatted is a computed read-only display field
                    (new class ($fieldMappings) extends Attribute {
                        public function __construct(protected array $fieldMappings)
                        {
                            parent::__construct('formatted');
                        }

                        protected function doRead(&$object, $attributes = []): string
                        {
                            $firstNameField = $this->fieldMappings['name_first'] ?? 'name_first';
                            $lastNameField = $this->fieldMappings['name_last'] ?? 'name_last';
                            return trim(($object->$firstNameField ?? '') . ' ' . ($object->$lastNameField ?? ''));
                        }

                        public function add($value, Model &$object): void
                        {
                            // formatted is computed — write is a no-op
                        }

                        public function replace($value, Model &$object, $path = null, $removeIfNotSet = false): void
                        {
                            // formatted is computed — write is a no-op
                        }
                    }),
                ),

                // emails multi-value, primary email
                $this->buildEmailsAttribute($fieldMappings),

                // phoneNumbers — mobile maps to phone_cell
                $this->buildPhoneNumbersAttribute($fieldMappings),

                // other — recovery_email maps to email_secondary
                $this->buildOtherAttribute($fieldMappings),

                // password — never returned; on create, ignored (observer sets a random one)
                (new class () extends Attribute {
                    protected function doRead(&$object, $attributes = []): mixed
                    {
                        return null;
                    }

                    public function add($value, Model &$object): void
                    {
                        // Intentionally ignored — password is managed by the service provider observer
                        $this->dirty = true;
                    }

                    public function replace($value, Model &$object, $path = null, $removeIfNotSet = false): void
                    {
                        // Never update password via SCIM
                    }
                })->setReturned('never'),
            ),
        );
    }

    /**
     * Build the emails attribute.
     */
    protected function buildEmailsAttribute(array $fieldMappings): Complex
    {
        $emailField = $fieldMappings['email'] ?? 'email';

        return (new class ($emailField) extends Complex {
            public function __construct(protected string $emailField)
            {
                parent::__construct('emails');
            }

            protected function doRead(&$object, $attributes = []): array
            {
                return [[
                    'value'   => $object->{$this->emailField},
                    'type'    => 'work',
                    'primary' => true,
                ]];
            }

            public function add($value, Model &$object): void
            {
                if (!empty($value[0]['value'])) {
                    $object->{$this->emailField} = $value[0]['value'];
                }
            }

            public function replace($value, Model &$object, $path = null, $removeIfNotSet = false): void
            {
                if (!empty($value[0]['value'])) {
                    $object->{$this->emailField} = $value[0]['value'];
                }
            }
        })->withSubAttributes(
            eloquent('value', $emailField)->ensure('required', 'email'),
            new Constant('type', 'work'),
            new Constant('primary', true),
        )->ensure('required', 'array')->setMultiValued(true);
    }

    /**
     * Build the phoneNumbers attribute.
     */
    protected function buildPhoneNumbersAttribute(array $fieldMappings): Complex
    {
        $phoneCellField = $fieldMappings['phone_cell'] ?? 'phone_cell';

        return (new class ($phoneCellField) extends Complex {
            public function __construct(protected string $phoneCellField)
            {
                parent::__construct('phoneNumbers');
            }

            protected function doRead(&$object, $attributes = []): array
            {
                if (!$object->{$this->phoneCellField}) return [];
                return [[
                    'value' => $object->{$this->phoneCellField},
                    'type' => 'mobile',
                    'primary' => true
                ]];
            }

            public function add($value, Model &$object): void
            {
                foreach ((array) $value as $phone) {
                    if (($phone['type'] ?? '') === 'mobile' && !empty($phone['value'])) {
                        $object->{$this->phoneCellField} = $phone['value'];
                    }
                }
            }

            public function replace($value, Model &$object, $path = null, $removeIfNotSet = false): void
            {
                foreach ((array) $value as $phone) {
                    if (($phone['type'] ?? '') === 'mobile' && !empty($phone['value'])) {
                        $object->{$this->phoneCellField} = $phone['value'];
                    }
                }
            }
        })->setMultiValued(true);
    }

    /**
     * Build the other/recovery email attribute.
     */
    protected function buildOtherAttribute(array $fieldMappings): Complex
    {
        $emailSecondaryField = $fieldMappings['email_secondary'] ?? 'email_secondary';

        return (new class ($emailSecondaryField) extends Complex {
            public function __construct(protected string $emailSecondaryField)
            {
                parent::__construct('other');
            }

            protected function doRead(&$object, $attributes = []): array
            {
                if (!$object->{$this->emailSecondaryField}) return [];
                return [[
                    'value' => $object->{$this->emailSecondaryField},
                    'type' => 'recovery_email',
                    'primary' => false
                ]];
            }

            public function add($value, Model &$object): void
            {
                foreach ((array) $value as $item) {
                    if (($item['type'] ?? '') === 'recovery_email' && !empty($item['value'])) {
                        $object->{$this->emailSecondaryField} = $item['value'];
                    }
                }
            }

            public function replace($value, Model &$object, $path = null, $removeIfNotSet = false): void
            {
                foreach ((array) $value as $item) {
                    if (($item['type'] ?? '') === 'recovery_email' && !empty($item['value'])) {
                        $object->{$this->emailSecondaryField} = $item['value'];
                    }
                }
            }
        })->setMultiValued(true);
    }

    /**
     * Get the Group resource configuration for SCIM.
     * Groups are not used by default — return empty config to disable Group resource type.
     */
    public function getGroupConfig(): array
    {
        return [];
    }

    /**
     * Get the full SCIM configuration.
     */
    public function getConfig(): array
    {
        return [
            'Users' => $this->getUserConfig(),
        ];
    }
}
