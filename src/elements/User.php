<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\actions\DeleteUsers;
use craft\elements\actions\Edit;
use craft\elements\actions\Restore;
use craft\elements\actions\SuspendUsers;
use craft\elements\actions\UnsuspendUsers;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\UserQuery;
use craft\events\AuthenticateUserEvent;
use craft\events\DefineValueEvent;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Session;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\i18n\Formatter;
use craft\i18n\Locale;
use craft\models\UserGroup;
use craft\records\User as UserRecord;
use craft\validators\DateTimeValidator;
use craft\validators\UniqueValidator;
use craft\validators\UsernameValidator;
use craft\validators\UserPasswordValidator;
use yii\base\ErrorHandler;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;
use yii\validators\InlineValidator;
use yii\validators\Validator;
use yii\web\IdentityInterface;

/**
 * User represents a user element.
 *
 * @property \DateTime|null $cooldownEndTime the time when the user will be over their cooldown period
 * @property string|null $friendlyName the user's first name or username
 * @property string|null $fullName the user's full name
 * @property UserGroup[] $groups the user's groups
 * @property bool $isCurrent whether this is the current logged-in user
 * @property string $name the user's full name or username
 * @property Asset|null $photo the user's photo
 * @property array $preferences the user’s preferences
 * @property string|null $preferredLanguage the user’s preferred language
 * @property string|null $preferredLocale the user’s preferred formatting locale
 * @property \DateInterval|null $remainingCooldownTime the remaining cooldown time for this user, if they've entered their password incorrectly too many times
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class User extends Element implements IdentityInterface
{
    /**
     * @event AuthenticateUserEvent The event that is triggered before a user is authenticated.
     *
     * If you wish to offload authentication logic, then set [[AuthenticateUserEvent::$performAuthentication]] to `false`, and set [[$authError]] to
     * something if there is an authentication error.
     */
    const EVENT_BEFORE_AUTHENTICATE = 'beforeAuthenticate';

    /**
     * @event DefineValueEvent The event that is triggered when defining the user’s name, as returned by [[getName()]] or [[__toString()]].
     * @since 3.7.0
     */
    const EVENT_DEFINE_NAME = 'defineName';

    /**
     * @event DefineValueEvent The event that is triggered when defining the user’s friendly name, as returned by [[getFriendlyName()]].
     * @since 3.7.0
     */
    const EVENT_DEFINE_FRIENDLY_NAME = 'defineFriendlyName';

    const IMPERSONATE_KEY = 'Craft.UserSessionService.prevImpersonateUserId';

    // User statuses
    // -------------------------------------------------------------------------

    const STATUS_ACTIVE = 'active';
    const STATUS_LOCKED = 'locked';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_PENDING = 'pending';

    // Authentication error codes
    // -------------------------------------------------------------------------

    const AUTH_INVALID_CREDENTIALS = 'invalid_credentials';
    const AUTH_PENDING_VERIFICATION = 'pending_verification';
    const AUTH_ACCOUNT_LOCKED = 'account_locked';
    const AUTH_ACCOUNT_COOLDOWN = 'account_cooldown';
    const AUTH_PASSWORD_RESET_REQUIRED = 'password_reset_required';
    const AUTH_ACCOUNT_SUSPENDED = 'account_suspended';
    const AUTH_NO_CP_ACCESS = 'no_cp_access';
    const AUTH_NO_CP_OFFLINE_ACCESS = 'no_cp_offline_access';
    const AUTH_NO_SITE_OFFLINE_ACCESS = 'no_site_offline_access';

    // Validation scenarios
    // -------------------------------------------------------------------------

    const SCENARIO_REGISTRATION = 'registration';
    const SCENARIO_PASSWORD = 'password';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'User');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('app', 'user');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('app', 'Users');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('app', 'users');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle()
    {
        return 'user';
    }

    /**
     * @inheritdoc
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => Craft::t('app', 'Active'),
            self::STATUS_PENDING => Craft::t('app', 'Pending'),
            self::STATUS_SUSPENDED => Craft::t('app', 'Suspended'),
            self::STATUS_LOCKED => Craft::t('app', 'Locked'),
        ];
    }

    /**
     * @inheritdoc
     * @return UserQuery The newly created [[UserQuery]] instance.
     */
    public static function find(): ElementQueryInterface
    {
        return new UserQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('app', 'All users'),
                'hasThumbs' => true,
                'data' => [
                    'slug' => 'all',
                ],
            ],
        ];

        if (Craft::$app->getEdition() === Craft::Pro) {
            // Admin source
            $sources[] = [
                'key' => 'admins',
                'label' => Craft::t('app', 'Admins'),
                'criteria' => ['admin' => true],
                'hasThumbs' => true,
                'data' => [
                    'slug' => 'admins',
                ],
            ];

            $groups = Craft::$app->getUserGroups()->getAllGroups();

            if (!empty($groups)) {
                $sources[] = ['heading' => Craft::t('app', 'Groups')];

                foreach ($groups as $group) {
                    $sources[] = [
                        'key' => 'group:' . $group->uid,
                        'label' => Craft::t('site', $group->name),
                        'criteria' => ['groupId' => $group->id],
                        'hasThumbs' => true,
                        'data' => [
                            'slug' => $group->handle,
                        ],
                    ];
                }
            }
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source = null): array
    {
        $actions = [];
        $elementsService = Craft::$app->getElements();

        // Edit
        $actions[] = $elementsService->createAction([
            'type' => Edit::class,
            'label' => Craft::t('app', 'Edit user'),
        ]);

        if (Craft::$app->getUser()->checkPermission('moderateUsers')) {
            // Suspend
            $actions[] = SuspendUsers::class;

            // Unsuspend
            $actions[] = UnsuspendUsers::class;
        }

        if (Craft::$app->getUser()->checkPermission('deleteUsers')) {
            // Delete
            $actions[] = DeleteUsers::class;
        }

        // Restore
        $actions[] = $elementsService->createAction([
            'type' => Restore::class,
            'successMessage' => Craft::t('app', 'Users restored.'),
            'partialSuccessMessage' => Craft::t('app', 'Some users restored.'),
            'failMessage' => Craft::t('app', 'Users not restored.'),
        ]);

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['username', 'firstName', 'lastName', 'fullName', 'email'];
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        if (Craft::$app->getConfig()->getGeneral()->useEmailAsUsername) {
            $attributes = [
                'email' => Craft::t('app', 'Email'),
                'firstName' => Craft::t('app', 'First Name'),
                'lastName' => Craft::t('app', 'Last Name'),
                [
                    'label' => Craft::t('app', 'Last Login'),
                    'orderBy' => 'lastLoginDate',
                    'defaultDir' => 'desc',
                ],
                [
                    'label' => Craft::t('app', 'Date Created'),
                    'orderBy' => 'elements.dateCreated',
                    'attribute' => 'dateCreated',
                    'defaultDir' => 'desc',
                ],
                [
                    'label' => Craft::t('app', 'Date Updated'),
                    'orderBy' => 'elements.dateUpdated',
                    'attribute' => 'dateUpdated',
                    'defaultDir' => 'desc',
                ],
                [
                    'label' => Craft::t('app', 'ID'),
                    'orderBy' => 'elements.id',
                    'attribute' => 'id',
                ],
            ];
        } else {
            $attributes = [
                'username' => Craft::t('app', 'Username'),
                'firstName' => Craft::t('app', 'First Name'),
                'lastName' => Craft::t('app', 'Last Name'),
                'email' => Craft::t('app', 'Email'),
                [
                    'label' => Craft::t('app', 'Last Login'),
                    'orderBy' => 'lastLoginDate',
                    'defaultDir' => 'desc',
                ],
                [
                    'label' => Craft::t('app', 'Date Created'),
                    'orderBy' => 'elements.dateCreated',
                    'attribute' => 'dateCreated',
                    'defaultDir' => 'desc',
                ],
                [
                    'label' => Craft::t('app', 'Date Updated'),
                    'orderBy' => 'elements.dateUpdated',
                    'attribute' => 'dateUpdated',
                    'defaultDir' => 'desc',
                ],
                [
                    'label' => Craft::t('app', 'ID'),
                    'orderBy' => 'elements.id',
                    'attribute' => 'id',
                ],
            ];
        }

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'user' => ['label' => Craft::t('app', 'User')],
            'email' => ['label' => Craft::t('app', 'Email')],
            'username' => ['label' => Craft::t('app', 'Username')],
            'fullName' => ['label' => Craft::t('app', 'Full Name')],
            'firstName' => ['label' => Craft::t('app', 'First Name')],
            'lastName' => ['label' => Craft::t('app', 'Last Name')],
            'groups' => ['label' => Craft::t('app', 'Groups')],
            'preferredLanguage' => ['label' => Craft::t('app', 'Preferred Language')],
            'preferredLocale' => ['label' => Craft::t('app', 'Preferred Locale')],
            'id' => ['label' => Craft::t('app', 'ID')],
            'uid' => ['label' => Craft::t('app', 'UID')],
            'lastLoginDate' => ['label' => Craft::t('app', 'Last Login')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'fullName',
            'email',
            'dateCreated',
            'lastLoginDate',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function prepElementQueryForTableAttribute(ElementQueryInterface $elementQuery, string $attribute)
    {
        /** @var UserQuery $elementQuery */
        if ($attribute === 'groups') {
            $elementQuery->withGroups();
        } else {
            parent::prepElementQueryForTableAttribute($elementQuery, $attribute);
        }
    }

    /**
     * @inheritdoc
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle)
    {
        if ($handle === 'photo') {
            // Get the source element IDs
            $sourceElementIds = ArrayHelper::getColumn($sourceElements, 'id');

            $map = (new Query())
                ->select(['id as source', 'photoId as target'])
                ->from([Table::USERS])
                ->where(['id' => $sourceElementIds])
                ->andWhere(['not', ['photoId' => null]])
                ->all();

            return [
                'elementType' => Asset::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * @inheritdoc
     * @since 3.3.0
     */
    public static function gqlTypeNameByContext($context): string
    {
        return 'User';
    }

    // IdentityInterface Methods
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public static function findIdentity($id)
    {
        $user = static::find()
            ->addSelect(['users.password'])
            ->id($id)
            ->anyStatus()
            ->one();

        if ($user === null) {
            return null;
        }

        /** @var static $user */
        if ($user->getStatus() === self::STATUS_ACTIVE) {
            return $user;
        }

        // If the current user is being impersonated by an admin, ignore their status
        if ($previousUserId = Session::get(self::IMPERSONATE_KEY)) {
            $previousUser = static::find()
                ->id($previousUserId)
                ->anyStatus()
                ->one();

            if ($previousUser && $previousUser->can('impersonateUsers')) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    /**
     * @var string|null Username
     */
    public $username;

    /**
     * @var int|null Photo asset id
     */
    public $photoId;

    /**
     * @var string|null First name
     */
    public $firstName;

    /**
     * @var string|null Last name
     */
    public $lastName;

    /**
     * @var string|null Email
     */
    public $email;

    /**
     * @var string|null Password
     */
    public $password;

    /**
     * @var bool Admin
     */
    public $admin = false;

    /**
     * @var bool Locked
     */
    public $locked = false;

    /**
     * @var bool Suspended
     */
    public $suspended = false;

    /**
     * @var bool Pending
     */
    public $pending = false;

    /**
     * @var \DateTime|null Last login date
     */
    public $lastLoginDate;

    /**
     * @var int|null Invalid login count
     */
    public $invalidLoginCount;

    /**
     * @var \DateTime|null Last invalid login date
     */
    public $lastInvalidLoginDate;

    /**
     * @var \DateTime|null Lockout date
     */
    public $lockoutDate;

    /**
     * @var bool Whether the user has a dashboard
     * @since 3.0.4
     */
    public $hasDashboard = false;

    /**
     * @var bool Password reset required
     */
    public $passwordResetRequired = false;

    /**
     * @var \DateTime|null Last password change date
     */
    public $lastPasswordChangeDate;

    /**
     * @var string|null Unverified email
     */
    public $unverifiedEmail;

    /**
     * @var string|null New password
     */
    public $newPassword;

    /**
     * @var string|null Current password
     */
    public $currentPassword;

    /**
     * @var \DateTime|null Verification code issued date
     */
    public $verificationCodeIssuedDate;

    /**
     * @var string|null Verification code
     */
    public $verificationCode;

    /**
     * @var string|null Last login attempt IP address.
     */
    public $lastLoginAttemptIp;

    /**
     * @var string|null Auth error
     */
    public $authError;

    /**
     * @var self|null The user who should take over the user’s content if the user is deleted.
     */
    public $inheritorOnDelete;

    /**
     * @var string|null
     * @see getName()
     * @see setName()
     */
    private $_name;

    /**
     * @var string|bool
     * @see getFriendlyName()
     * @see setFriendlyName()
     */
    private $_friendlyName;

    /**
     * @var Asset|false|null user photo
     */
    private $_photo;

    /**
     * @var UserGroup[]|null The cached list of groups the user belongs to. Set by [[getGroups()]].
     */
    private $_groups;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // Is this user in cooldown mode, and are they past their window?
        if (
            $this->locked &&
            Craft::$app->getConfig()->getGeneral()->cooldownDuration &&
            !$this->getRemainingCooldownTime()
        ) {
            Craft::$app->getUsers()->unlockUser($this);
        }

        // Convert IDNA ASCII to Unicode
        if ($this->username) {
            $this->username = StringHelper::idnToUtf8Email($this->username);
        }
        if ($this->email) {
            $this->email = StringHelper::idnToUtf8Email($this->email);
        }
    }

    /**
     * Use the full name or username as the string representation.
     *
     * @return string
     */
    public function __toString()
    {
        try {
            return $this->getName() ?: static::class;
        } catch (\Exception $e) {
            ErrorHandler::convertExceptionToError($e);
        }
    }

    /**
     * @inheritdoc
     */
    public function attributes()
    {
        $names = parent::attributes();
        $names[] = 'cooldownEndTime';
        $names[] = 'friendlyName';
        $names[] = 'fullName';
        $names[] = 'isCurrent';
        $names[] = 'name';
        $names[] = 'preferredLanguage';
        $names[] = 'remainingCooldownTime';
        return $names;
    }

    /**
     * @inheritdoc
     */
    public function extraFields()
    {
        $names = parent::extraFields();
        $names[] = 'groups';
        $names[] = 'photo';
        return $names;
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'lastLoginDate';
        $attributes[] = 'lastInvalidLoginDate';
        $attributes[] = 'lockoutDate';
        $attributes[] = 'lastPasswordChangeDate';
        $attributes[] = 'verificationCodeIssuedDate';
        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        $labels = parent::attributeLabels();
        $labels['currentPassword'] = Craft::t('app', 'Current Password');
        $labels['email'] = Craft::t('app', 'Email');
        $labels['firstName'] = Craft::t('app', 'First Name');
        $labels['lastName'] = Craft::t('app', 'Last Name');
        $labels['newPassword'] = Craft::t('app', 'New Password');
        $labels['password'] = Craft::t('app', 'Password');
        $labels['unverifiedEmail'] = Craft::t('app', 'Email');
        $labels['username'] = Craft::t('app', 'Username');
        return $labels;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['lastLoginDate', 'lastInvalidLoginDate', 'lockoutDate', 'lastPasswordChangeDate', 'verificationCodeIssuedDate'], DateTimeValidator::class];
        $rules[] = [['invalidLoginCount', 'photoId'], 'number', 'integerOnly' => true];
        $rules[] = [['username', 'email', 'unverifiedEmail', 'firstName', 'lastName'], 'trim', 'skipOnEmpty' => true];
        $rules[] = [['email', 'unverifiedEmail'], 'email', 'enableIDN' => App::supportsIdn(), 'enableLocalIDN' => false];
        $rules[] = [['email', 'password', 'unverifiedEmail'], 'string', 'max' => 255];
        $rules[] = [['username', 'firstName', 'lastName', 'verificationCode'], 'string', 'max' => 100];
        $rules[] = [['email'], 'required'];
        $rules[] = [['lastLoginAttemptIp'], 'string', 'max' => 45];

        if (!Craft::$app->getConfig()->getGeneral()->useEmailAsUsername) {
            $rules[] = [['username'], 'required'];
            $rules[] = [['username'], UsernameValidator::class];
        }

        if (Craft::$app->getIsInstalled()) {
            $rules[] = [
                ['username', 'email'],
                UniqueValidator::class,
                'targetClass' => UserRecord::class,
                'caseInsensitive' => true,
            ];

            $rules[] = [['unverifiedEmail'], 'validateUnverifiedEmail'];
        }

        if ($this->id !== null && $this->passwordResetRequired) {
            // Get the current password hash
            $currentPassword = (new Query())
                ->select(['password'])
                ->from([Table::USERS])
                ->where(['id' => $this->id])
                ->scalar();
        } else {
            $currentPassword = null;
        }

        $rules[] = [
            ['newPassword'],
            UserPasswordValidator::class,
            'forceDifferent' => $this->passwordResetRequired,
            'currentPassword' => $currentPassword,
        ];

        $rules[] = [
            ['firstName', 'lastName'], function($attribute, $params, Validator $validator) {
                if (strpos($this->$attribute, '://') !== false) {
                    $validator->addError($this, $attribute, Craft::t('app', 'Invalid value “{value}”.'));
                }
            },
        ];

        return $rules;
    }

    /**
     * Validates the unverifiedEmail value is unique.
     *
     * @param string $attribute
     * @param array|null $params
     * @param InlineValidator $validator
     */
    public function validateUnverifiedEmail(string $attribute, $params, InlineValidator $validator)
    {
        $query = self::find()
            ->anyStatus();

        if (Craft::$app->getDb()->getIsMysql()) {
            $query->where([
                'email' => $this->unverifiedEmail,
            ]);
        } else {
            // Postgres is case-sensitive
            $query->where([
                'lower([[email]])' => mb_strtolower($this->unverifiedEmail),
            ]);
        }

        if ($this->id) {
            $query->andWhere(['not', ['elements.id' => $this->id]]);
        }

        if ($query->exists()) {
            $validator->addError($this, $attribute, Craft::t('yii', '{attribute} "{value}" has already been taken.'), $params);
        }
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_PASSWORD] = ['newPassword'];
        $scenarios[self::SCENARIO_REGISTRATION] = ['username', 'email', 'newPassword'];

        return $scenarios;
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout()
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    /**
     * @inheritdoc
     */
    public function getAuthKey()
    {
        $token = Craft::$app->getUser()->getToken();

        if ($token === null) {
            throw new Exception('No user session token exists.');
        }

        $userAgent = Craft::$app->getRequest()->getUserAgent();

        // The auth key is a combination of the hashed token, its row's UID, and the user agent string
        return json_encode([
            $token,
            null,
            md5($userAgent),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @inheritdoc
     */
    public function validateAuthKey($authKey)
    {
        $data = Json::decodeIfJson($authKey);

        if (!is_array($data) || count($data) !== 3 || !isset($data[0], $data[2])) {
            return false;
        }

        [$token, , $userAgent] = $data;

        if (!$this->_validateUserAgent($userAgent)) {
            return false;
        }

        $tokenId = (new Query())
            ->select(['id'])
            ->from([Table::SESSIONS])
            ->where([
                'token' => $token,
                'userId' => $this->id,
            ])
            ->scalar();

        if (!$tokenId) {
            return false;
        }

        // Update the session row's dateUpdated value so it doesn't get GC'd
        Db::update(Table::SESSIONS, [
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ], ['id' => $tokenId]);

        return true;
    }

    /**
     * Determines whether the user is allowed to be logged in with a given password.
     *
     * @param string $password The user's plain text password.
     * @return bool
     */
    public function authenticate(string $password): bool
    {
        $this->authError = null;

        // Fire a 'beforeAuthenticate' event
        $event = new AuthenticateUserEvent([
            'password' => $password,
        ]);
        $this->trigger(self::EVENT_BEFORE_AUTHENTICATE, $event);

        if ($this->authError === null && $event->performAuthentication) {
            // Validate the password
            try {
                $passwordValid = Craft::$app->getSecurity()->validatePassword($password, $this->password);
            } catch (InvalidArgumentException $e) {
                $passwordValid = false;
            }

            if ($passwordValid) {
                $this->authError = $this->_getAuthError();
            } else {
                Craft::$app->getUsers()->handleInvalidLogin($this);
                // Was that one bad password too many?
                if ($this->locked && !Craft::$app->getConfig()->getGeneral()->preventUserEnumeration) {
                    // Will set the authError to either AccountCooldown or AccountLocked
                    $this->authError = $this->_getAuthError();
                } else {
                    $this->authError = self::AUTH_INVALID_CREDENTIALS;
                }
            }
        }

        return $this->authError === null;
    }

    /**
     * Returns the reference string to this element.
     *
     * @return string|null
     */
    public function getRef()
    {
        return $this->username;
    }

    /**
     * Returns the user's groups.
     *
     * @return UserGroup[]
     */
    public function getGroups(): array
    {
        if ($this->_groups !== null) {
            return $this->_groups;
        }

        if (Craft::$app->getEdition() !== Craft::Pro || $this->id === null) {
            return [];
        }

        return $this->_groups = Craft::$app->getUserGroups()->getGroupsByUserId($this->id);
    }

    /**
     * Sets an array of user groups on the user.
     *
     * @param UserGroup[] $groups An array of UserGroup objects.
     */
    public function setGroups(array $groups)
    {
        if (Craft::$app->getEdition() === Craft::Pro) {
            $this->_groups = $groups;
        }
    }

    /**
     * Returns whether the user is in a specific group.
     *
     * @param UserGroup|int|string $group The user group model, its handle, or ID.
     * @return bool
     */
    public function isInGroup($group): bool
    {
        if (Craft::$app->getEdition() !== Craft::Pro) {
            return false;
        }

        if (is_object($group) && $group instanceof UserGroup) {
            $group = $group->id;
        }

        if (is_numeric($group)) {
            return in_array($group, ArrayHelper::getColumn($this->getGroups(), 'id'), false);
        }

        return in_array($group, ArrayHelper::getColumn($this->getGroups(), 'handle'), true);
    }

    /**
     * Returns the user's full name.
     *
     * @return string|null
     */
    public function getFullName()
    {
        $firstName = trim($this->firstName);
        $lastName = trim($this->lastName);

        if (!$firstName && !$lastName) {
            return null;
        }

        $name = $firstName;

        if ($firstName && $lastName) {
            $name .= ' ';
        }

        $name .= $lastName;

        return $name;
    }

    /**
     * Returns the user's full name or username.
     *
     * @return string
     */
    public function getName(): string
    {
        if ($this->_name === null) {
            $this->_name = $this->_defineName();
        }

        return $this->_name;
    }

    /**
     * @return string
     */
    private function _defineName(): string
    {
        if ($this->hasEventHandlers(self::EVENT_DEFINE_NAME)) {
            $this->trigger(self::EVENT_DEFINE_NAME, $event = new DefineValueEvent());
            if ($event->value !== null) {
                return $event->value;
            }
        }

        if (($fullName = $this->getFullName()) !== null) {
            return $fullName;
        }

        return (string)$this->username;
    }

    /**
     * Sets the user’s name.
     *
     * @param string $name
     * @return void
     * @since 3.7.0
     */
    public function setName(string $name): void
    {
        $this->_name = $name;
    }

    /**
     * Returns the user's first name or username.
     *
     * @return string|null
     */
    public function getFriendlyName(): ?string
    {
        if ($this->_friendlyName === null) {
            $this->_friendlyName = $this->_defineFriendlyName() ?? false;
        }

        return $this->_friendlyName ?: null;
    }

    /**
     * @return string|null
     */
    private function _defineFriendlyName(): ?string
    {
        if ($this->hasEventHandlers(self::EVENT_DEFINE_FRIENDLY_NAME)) {
            $this->trigger(self::EVENT_DEFINE_FRIENDLY_NAME, $event = new DefineValueEvent());
            if ($event->handled || $event->value !== null) {
                return $event->value;
            }
        }

        if ($firstName = trim($this->firstName)) {
            return $firstName;
        }

        return $this->username;
    }

    /**
     * Sets the user’s friendly name.
     *
     * @param string $friendlyName
     * @return void
     * @since 3.7.0
     */
    public function setFriendlyName(string $friendlyName): void
    {
        $this->_friendlyName = $friendlyName;
    }

    /**
     * @inheritdoc
     */
    public function getStatus()
    {
        if ($this->suspended) {
            return self::STATUS_SUSPENDED;
        }

        if ($this->archived) {
            return self::STATUS_ARCHIVED;
        }

        if ($this->pending) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Returns the URL to the user's photo.
     *
     * @param int $size The width and height the photo should be sized to
     * @return string|null
     * @deprecated in 3.0.0. Use getPhoto().getUrl() instead.
     */
    public function getPhotoUrl(int $size = 100)
    {
        Craft::$app->getDeprecator()->log('User::getPhotoUrl()', '`User::getPhotoUrl()` has been deprecated. Use `getPhoto()` to access the photo asset (if there is one), and call its `getUrl()` method to access the photo URL.');
        $photo = $this->getPhoto();

        if ($photo) {
            return $photo->getUrl([
                'width' => $size,
                'height' => $size,
            ]);
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function getThumbUrl(int $size)
    {
        $photo = $this->getPhoto();

        if ($photo) {
            return Craft::$app->getAssets()->getThumbUrl($photo, $size, $size, false);
        }

        return Craft::$app->getAssetManager()->getPublishedUrl('@app/web/assets/cp/dist', true, 'images/user.svg');
    }

    /**
     * @inheritdoc
     */
    public function getHasRoundedThumb(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function isEditable(): bool
    {
        return Craft::$app->getUser()->checkPermission('editUsers');
    }

    /**
     * Returns whether this is the current logged-in user.
     *
     * @return bool
     */
    public function getIsCurrent(): bool
    {
        if (!$this->id) {
            return false;
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        return $currentUser && $currentUser->id == $this->id;
    }

    /**
     * Returns whether the user has permission to perform a given action.
     *
     * @param string $permission
     * @return bool
     */
    public function can(string $permission): bool
    {
        if (Craft::$app->getEdition() === Craft::Pro) {
            if ($this->admin) {
                return true;
            }

            if ($this->id !== null) {
                return Craft::$app->getUserPermissions()->doesUserHavePermission($this->id, $permission);
            }

            return false;
        }

        return true;
    }

    /**
     * Returns whether the user has shunned a given message.
     *
     * @param string $message
     * @return bool
     */
    public function hasShunned(string $message): bool
    {
        if ($this->id !== null) {
            return Craft::$app->getUsers()->hasUserShunnedMessage($this->id, $message);
        }

        return false;
    }

    /**
     * Returns the time when the user will be over their cooldown period.
     *
     * @return \DateTime|null
     */
    public function getCooldownEndTime()
    {
        // There was an old bug that where a user's lockoutDate could be null if they've
        // passed their cooldownDuration already, but there account status is still locked.
        // If that's the case, just let it return null as if they are past the cooldownDuration.
        if ($this->locked && $this->lockoutDate) {
            $generalConfig = Craft::$app->getConfig()->getGeneral();
            $interval = DateTimeHelper::secondsToInterval($generalConfig->cooldownDuration);
            $cooldownEnd = clone $this->lockoutDate;
            $cooldownEnd->add($interval);

            return $cooldownEnd;
        }

        return null;
    }

    /**
     * Returns the remaining cooldown time for this user, if they've entered their password incorrectly too many times.
     *
     * @return \DateInterval|null
     */
    public function getRemainingCooldownTime()
    {
        if ($this->locked) {
            $currentTime = DateTimeHelper::currentUTCDateTime();
            $cooldownEnd = $this->getCooldownEndTime();

            if ($currentTime < $cooldownEnd) {
                return $currentTime->diff($cooldownEnd);
            }
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    protected function cpEditUrl(): ?string
    {
        if (Craft::$app->getRequest()->getIsCpRequest() && $this->getIsCurrent()) {
            return UrlHelper::cpUrl('myaccount');
        }

        if (Craft::$app->getEdition() === Craft::Pro) {
            return UrlHelper::cpUrl('users/' . $this->id);
        }

        return null;
    }

    /**
     * Returns the user’s preferences.
     *
     * @return array The user’s preferences.
     */
    public function getPreferences(): array
    {
        return $this->id ? Craft::$app->getUsers()->getUserPreferences($this->id) : [];
    }

    /**
     * Returns one of the user’s preferences by its key.
     *
     * @param string $key The preference’s key
     * @param mixed $default The default value, if the preference hasn’t been set
     * @return mixed The user’s preference
     */
    public function getPreference(string $key, $default = null)
    {
        $preferences = $this->getPreferences();

        return $preferences[$key] ?? $default;
    }

    /**
     * Returns the user’s preferred language, if they have one.
     *
     * @return string|null The preferred language
     */
    public function getPreferredLanguage()
    {
        return $this->_validateLocale($this->getPreference('language'));
    }

    /**
     * Returns the user’s preferred locale to be used for date/number formatting, if they have one.
     *
     * If the user doesn’t have a preferred locale, their preferred language will be used instead.
     *
     * @return string|null The preferred locale
     * @since 3.5.0
     */
    public function getPreferredLocale()
    {
        return $this->_validateLocale($this->getPreference('locale'));
    }

    /**
     * Validates and returns a locale ID.
     *
     * @param string|null $locale
     * @return string|null
     */
    private function _validateLocale(string $locale = null)
    {
        if ($locale !== null && in_array($locale, Craft::$app->getI18n()->getAppLocaleIds(), true)) {
            return $locale;
        }

        return null;
    }

    /**
     * Merges new user preferences with the existing ones, and returns the result.
     *
     * @param array $preferences The new preferences
     * @return array The user’s new preferences.
     * @deprecated in 3.7.27.
     */
    public function mergePreferences(array $preferences): array
    {
        return $preferences + $this->getPreferences();
    }

    /**
     * @inheritdoc
     */
    public function setEagerLoadedElements(string $handle, array $elements)
    {
        if ($handle === 'photo') {
            $photo = $elements[0] ?? null;
            $this->setPhoto($photo);
        } else {
            parent::setEagerLoadedElements($handle, $elements);
        }
    }

    /**
     * Returns the user's photo.
     *
     * @return Asset|null
     */
    public function getPhoto()
    {
        if ($this->_photo === null) {
            if (!$this->photoId) {
                return null;
            }

            $this->_photo = Craft::$app->getAssets()->getAssetById($this->photoId) ?? false;
        }

        return $this->_photo ?: null;
    }

    /**
     * Sets the entry's author.
     *
     * @param Asset|null $photo
     */
    public function setPhoto(Asset $photo = null)
    {
        $this->_photo = $photo;
        $this->photoId = $photo->id ?? null;
    }

    // Indexes, etc.
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'email':
                return $this->email ? Html::mailto(Html::encode($this->email)) : '';

            case 'groups':
                return implode(', ', array_map(function(UserGroup $group) {
                    return Html::encode(Craft::t('site', $group->name));
                }, $this->getGroups()));

            case 'preferredLanguage':
                $language = $this->getPreferredLanguage();
                return $language ? (new Locale($language))->getDisplayName(Craft::$app->language) : '';

            case 'preferredLocale':
                $locale = $this->getPreferredLocale();
                return $locale ? (new Locale($locale))->getDisplayName(Craft::$app->language) : '';
        }

        return parent::tableAttributeHtml($attribute);
    }

    /**
     * @inheritdoc
     */
    protected function htmlAttributes(string $context): array
    {
        $attributes = [];

        if ($this->suspended) {
            $attributes['data-suspended'] = null;
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser && Craft::$app->getUsers()->canSuspend($currentUser, $this)) {
            $attributes['data-can-suspend'] = null;
        }

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    protected function metaFieldsHtml(): string
    {
        return implode('', [
            Craft::$app->getView()->renderTemplate('users/_accountfields', [
                'user' => $this,
                'isNewUser' => !$this->id,
            ]),
            parent::metaFieldsHtml(),
        ]);
    }

    protected function metadata(): array
    {
        $formatter = Craft::$app->getFormatter();

        return [
            Craft::t('app', 'Email') => Html::a($this->email, "mailto:$this->email"),
            Craft::t('app', 'Cooldown Time Remaining') => function() use ($formatter) {
                if (
                    !$this->locked ||
                    !Craft::$app->getConfig()->getGeneral()->cooldownDuration ||
                    ($duration = $this->getRemainingCooldownTime()) === null
                ) {
                    return false;
                }
                return $formatter->asDuration($duration);
            },
            Craft::t('app', 'Registered at') => $formatter->asDatetime($this->dateCreated, Formatter::FORMAT_WIDTH_SHORT),
            Craft::t('app', 'Last login') => function() use ($formatter) {
                if ($this->pending) {
                    return false;
                }
                if (!$this->lastLoginDate) {
                    return Craft::t('app', 'Never');
                }
                return $formatter->asDatetime($this->lastLoginDate, Formatter::FORMAT_WIDTH_SHORT);
            },
            Craft::t('app', 'Last login fail') => function() use ($formatter) {
                if (!$this->locked || !$this->lastInvalidLoginDate) {
                    return false;
                }
                return $formatter->asDatetime($this->lastInvalidLoginDate, Formatter::FORMAT_WIDTH_SHORT);
            },
            Craft::t('app', 'Login fail count') => function() use ($formatter) {
                if (!$this->locked) {
                    return false;
                }
                return $formatter->asDecimal($this->invalidLoginCount, 0);
            },
        ];
    }

    /**
     * @inheritdoc
     * @since 3.3.0
     */
    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext($this);
    }

    // Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     * @throws Exception if reasons
     */
    public function afterSave(bool $isNew)
    {
        // Get the user record
        if (!$isNew) {
            $record = UserRecord::findOne($this->id);

            if (!$record) {
                throw new Exception('Invalid user ID: ' . $this->id);
            }

            if ($this->locked != $record->locked) {
                throw new Exception('Unable to change a user’s locked state like this.');
            }

            if ($this->suspended != $record->suspended) {
                throw new Exception('Unable to change a user’s suspended state like this.');
            }

            if ($this->pending != $record->pending) {
                throw new Exception('Unable to change a user’s pending state like this.');
            }
        } else {
            $record = new UserRecord();
            $record->id = (int)$this->id;
            $record->locked = $this->locked;
            $record->suspended = $this->suspended;
            $record->pending = $this->pending;
        }

        $record->username = $this->username;
        $record->firstName = $this->firstName;
        $record->lastName = $this->lastName;
        $record->photoId = (int)$this->photoId ?: null;
        $record->email = $this->email;
        $record->admin = $this->admin;
        $record->passwordResetRequired = $this->passwordResetRequired;
        $record->unverifiedEmail = $this->unverifiedEmail;

        if ($changePassword = ($this->newPassword !== null)) {
            $hash = Craft::$app->getSecurity()->hashPassword($this->newPassword);

            $record->password = $this->password = $hash;
            $record->invalidLoginWindowStart = null;
            $record->invalidLoginCount = $this->invalidLoginCount = null;
            $record->verificationCode = null;
            $record->verificationCodeIssuedDate = null;
            $record->lastPasswordChangeDate = $this->lastPasswordChangeDate = DateTimeHelper::currentUTCDateTime();

            // If the user required a password reset *before this request*, then set passwordResetRequired to false
            if (!$isNew && $record->getOldAttribute('passwordResetRequired')) {
                $record->passwordResetRequired = $this->passwordResetRequired = false;
            }

            $this->newPassword = null;
        }

        // Capture the dirty attributes from the record
        $dirtyAttributes = array_keys($record->getDirtyAttributes());

        $record->save(false);

        // Make sure that the photo is located in the right place
        if (!$isNew && $this->photoId) {
            Craft::$app->getUsers()->relocateUserPhoto($this);
        }

        $this->setDirtyAttributes($dirtyAttributes);

        parent::afterSave($isNew);

        if (!$isNew && $changePassword) {
            // Destroy all other sessions for this user
            $condition = ['userId' => $this->id];
            if ($this->getIsCurrent() && $token = Craft::$app->getUser()->getToken()) {
                $condition = ['and', $condition, ['not', ['token' => $token]]];
            }
            Db::delete(Table::SESSIONS, $condition);
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        $elementsService = Craft::$app->getElements();

        // Do all this stuff within a transaction
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Should we transfer the content to a new user?
            if ($this->inheritorOnDelete) {
                // Invalidate all entry caches
                $elementsService->invalidateCachesForElementType(Entry::class);

                // Update the entry/version/draft tables to point to the new user
                $userRefs = [
                    Table::ENTRIES => 'authorId',
                    Table::DRAFTS => 'creatorId',
                    Table::REVISIONS => 'creatorId',
                ];

                foreach ($userRefs as $table => $column) {
                    Db::update($table, [
                        $column => $this->inheritorOnDelete->id,
                    ], [
                        $column => $this->id,
                    ], [], false);
                }
            } else {
                // Delete the entries
                $entryQuery = Entry::find()
                    ->site('*')
                    ->unique()
                    ->authorId($this->id)
                    ->anyStatus();

                foreach (Db::each($entryQuery) as $entry) {
                    /** @var Entry $entry */
                    $elementsService->deleteElement($entry);
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Validates a cookie's stored user agent against the current request's user agent string,
     * if the 'requireMatchingUserAgentForSession' config setting is enabled.
     *
     * @param string $userAgent
     * @return bool
     */
    private function _validateUserAgent(string $userAgent): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->requireMatchingUserAgentForSession) {
            return true;
        }

        $requestUserAgent = Craft::$app->getRequest()->getUserAgent();

        if (!hash_equals($userAgent, md5($requestUserAgent))) {
            Craft::warning('Tried to restore session from the the identity cookie, but the saved user agent (' . $userAgent . ') does not match the current request’s (' . $requestUserAgent . ').', __METHOD__);
            return false;
        }

        return true;
    }

    /**
     * Returns the [[authError]] value for [[authenticate()]]
     *
     * @return null|string
     */
    private function _getAuthError()
    {
        switch ($this->getStatus()) {
            case self::STATUS_ARCHIVED:
                return self::AUTH_INVALID_CREDENTIALS;
            case self::STATUS_PENDING:
                return self::AUTH_PENDING_VERIFICATION;
            case self::STATUS_SUSPENDED:
                return self::AUTH_ACCOUNT_SUSPENDED;
            case self::STATUS_ACTIVE:
                if ($this->locked) {
                    // Let them know how much time they have to wait (if any) before their account is unlocked.
                    if (Craft::$app->getConfig()->getGeneral()->cooldownDuration) {
                        return self::AUTH_ACCOUNT_COOLDOWN;
                    }
                    return self::AUTH_ACCOUNT_LOCKED;
                }
                // Is a password reset required?
                if ($this->passwordResetRequired) {
                    return self::AUTH_PASSWORD_RESET_REQUIRED;
                }
                $request = Craft::$app->getRequest();
                if (!$request->getIsConsoleRequest()) {
                    if ($request->getIsCpRequest()) {
                        if (!$this->can('accessCp')) {
                            return self::AUTH_NO_CP_ACCESS;
                        }
                        if (
                            Craft::$app->getIsLive() === false &&
                            $this->can('accessCpWhenSystemIsOff') === false
                        ) {
                            return self::AUTH_NO_CP_OFFLINE_ACCESS;
                        }
                    } else if (
                        Craft::$app->getIsLive() === false &&
                        $this->can('accessSiteWhenSystemIsOff') === false
                    ) {
                        return self::AUTH_NO_SITE_OFFLINE_ACCESS;
                    }
                }
        }

        return null;
    }
}
