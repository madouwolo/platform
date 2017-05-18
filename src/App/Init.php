<?php

// All services set in the container should follow a `prefix.name` format,
// such as `repository.user` or `validate.user.login` or `tool.hash.password`.
//
// When adding services that are private to a plugin, define them with a
// `namespace.`, such as `acme.tool.hash.magic`.
$di = service();

// Helpers, tools, etc
$di->set('tool.acl', $di->lazyNew(Ushahidi\App\Acl::class));
$di->setter[Ushahidi\App\Acl::class]['setRoleRepo'] = $di->lazyGet('repository.role');

$di->set('tool.hasher.password', $di->lazyNew(Ushahidi\App\Hasher\Password::class));
$di->set('tool.authenticator.password', $di->lazyNew(Ushahidi\App\Authenticator\Password::class));

$di->set('filereader.csv', $di->lazyNew(Ushahidi\App\FileReader\CSV::class));
$di->setter[Ushahidi\App\FileReader\CSV::class]['setReaderFactory'] =
	$di->lazyGet('csv.reader_factory');

$di->set('csv.reader_factory', $di->lazyNew(Ushahidi\App\FileReader\CSVReaderFactory::class));

// Register filesystem adapter types
// Currently supported: Local filesysten, AWS S3 v3, Rackspace
// the naming scheme must match the cdn type set in config/cdn
$di->set('adapter.local', $di->lazyNew(
	Ushahidi\App\FilesystemAdapter\Local::class,
	['config' => $di->lazyGet('cdn.config')]
));

$di->set('adapter.aws', $di->lazyNew(
	Ushahidi\App\FilesystemAdapter\AWS::class,
	['config' => $di->lazyGet('cdn.config')]
));

$di->set('adapter.rackspace', $di->lazyNew(
	Ushahidi\App\FilesystemAdapter\Rackspace::class,
	['config' => $di->lazyGet('cdn.config')]
));

// Media Filesystem
// The Ushahidi filesystem adapter returns a flysystem adapter for a given
// cdn type based on the provided configuration
$di->set('tool.filesystem', $di->lazyNew(Ushahidi\App\Filesystem::class));
$di->params[Ushahidi\App\Filesystem::class] = [
	'adapter' => $di->lazy(function () use ($di) {
			$adapter_type = $di->get('cdn.config');
			$fsa = $di->get('adapter.' . $adapter_type['type']);

			return $fsa->getAdapter();
	})
];

// Defined memcached
$di->set('memcached', $di->lazy(function () use ($di) {
	$config = $di->get('ratelimiter.config');

	$memcached = new Memcached();
	$memcached->addServer($config['memcached']['host'], $config['memcached']['port']);

	return $memcached;
}));

// Set up login rate limiter
$di->set('ratelimiter.login.flap', $di->lazyNew('BehEh\Flaps\Flap'));

$di->params['BehEh\Flaps\Flap'] = [
	'storage' => $di->lazyNew('BehEh\Flaps\Storage\DoctrineCacheAdapter'),
	'name' => 'login'
];

$di->set('ratelimiter.login.strategy', $di->lazyNew('BehEh\Flaps\Throttling\LeakyBucketStrategy'));

// 3 requests every 1 minute by default
$di->params['BehEh\Flaps\Throttling\LeakyBucketStrategy'] = [
	'requests' => 3,
	'timeSpan' => '1m'
];

$di->set('ratelimiter.login', $di->lazyNew(Ushahidi\App\RateLimiter::class));

$di->params[Ushahidi\App\RateLimiter::class] = [
	'flap' => $di->lazyGet('ratelimiter.login.flap'),
	'throttlingStrategy' => $di->lazyGet('ratelimiter.login.strategy'),
];

$di->params['BehEh\Flaps\Storage\DoctrineCacheAdapter'] = [
	'cache' => $di->lazyGet('ratelimiter.cache')
];

// Rate limit storage cache
$di->set('ratelimiter.cache', function () use ($di) {
	$config = $di->get('ratelimiter.config');
	$cache = $config['cache'];

	if ($cache === 'memcached') {
		$di->setter['Doctrine\Common\Cache\MemcachedCache']['setMemcached'] =
			$di->lazyGet('memcached');

		return $di->newInstance('Doctrine\Common\Cache\MemcachedCache');
	} elseif ($cache === 'filesystem') {
		$di->params['Doctrine\Common\Cache\FilesystemCache'] = [
			'directory' => $config['filesystem']['directory'],
		];

		return $di->newInstance('Doctrine\Common\Cache\FilesystemCache');
	}

	// Fall back to using in-memory cache if none is configured
	return $di->newInstance('Doctrine\Common\Cache\ArrayCache');
});

// Rate limiter violation handler
$di->setter['BehEh\Flaps\Flap']['setViolationHandler'] =
	$di->lazyNew(Ushahidi\App\ThrottlingViolationHandler::class);


// Validator mapping
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['config'] = [
	'update' => $di->lazyNew(Ushahidi\App\Validator\Config\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['forms'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Form\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Form\Update::class),
	'delete' => $di->lazyNew(Ushahidi\App\Validator\Form\Delete::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['form_attributes'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Form\Attribute\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Form\Attribute\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['form_roles'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Form\Role\Create::class),
	'update_collection' => $di->lazyNew(Ushahidi\App\Validator\Form\Role\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['form_stages'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Form\Stage\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Form\Stage\Update::class),
	'delete' => $di->lazyNew(Ushahidi\App\Validator\Form\Stage\Delete::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['layers'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Layer\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Layer\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['media'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Media\Create::class),
	'delete' => $di->lazyNew(Ushahidi\App\Validator\Media\Delete::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['posts'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Post\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Post\Create::class),
	'import' => $di->lazyNew(Ushahidi\App\Validator\Post\Import::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['tags'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Tag\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Tag\Update::class),
	'delete' => $di->lazyNew(Ushahidi\App\Validator\Tag\Delete::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['users'] = [
	'create'   => $di->lazyNew(Ushahidi\App\Validator\User\Create::class),
	'update'   => $di->lazyNew(Ushahidi\App\Validator\User\Update::class),
	'register' => $di->lazyNew(Ushahidi\App\Validator\User\Register::class)
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['messages'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Message\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Message\Update::class),
	'receive' => $di->lazyNew(Ushahidi\App\Validator\Message\Receive::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['savedsearches'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\SavedSearch\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\SavedSearch\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['sets'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Set\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Set\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['notifications'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Notification\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Notification\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['webhooks'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Webhook\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Webhook\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['contacts'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Contact\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Contact\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['sets_posts'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Set\Post\Create::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['csv'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\CSV\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\CSV\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['csv'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\CSV\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\CSV\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['roles'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Role\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Role\Update::class),
];
$di->params['Ushahidi\Factory\ValidatorFactory']['map']['permissions'] = [
	'create' => $di->lazyNew(Ushahidi\App\Validator\Permission\Create::class),
	'update' => $di->lazyNew(Ushahidi\App\Validator\Permission\Update::class),
];

// Validation Trait
$di->setter['Ushahidi\Core\Tool\ValidationEngineTrait']['setValidation'] = $di->newFactory('Ushahidi_ValidationEngine');
$di->params['Ushahidi_ValidationEngine']['array'] = [];

// Formatter mapping
$di->params['Ushahidi\Factory\FormatterFactory']['map'] = [
	'config'               => $di->lazyNew(Ushahidi\App\Formatter\Config::class),
	'dataproviders'        => $di->lazyNew(Ushahidi\App\Formatter\Dataprovider::class),
	'forms'                => $di->lazyNew(Ushahidi\App\Formatter\Form::class),
	'form_attributes'      => $di->lazyNew(Ushahidi\App\Formatter\Form\Attribute::class),
	'form_roles'           => $di->lazyNew(Ushahidi\App\Formatter\Form\Role::class),
	'form_stages'          => $di->lazyNew(Ushahidi\App\Formatter\Form\Stage::class),
	'layers'               => $di->lazyNew(Ushahidi\App\Formatter\Layer::class),
	'media'                => $di->lazyNew(Ushahidi\App\Formatter\Media::class),
	'messages'             => $di->lazyNew(Ushahidi\App\Formatter\Message::class),
	'posts'                => $di->lazyNew(Ushahidi\App\Formatter\Post::class),
	'tags'                 => $di->lazyNew(Ushahidi\App\Formatter\Tag::class),
	'savedsearches'        => $di->lazyNew(Ushahidi\App\Formatter\Set::class),
	'sets'                 => $di->lazyNew(Ushahidi\App\Formatter\Set::class),
	'sets_posts'           => $di->lazyNew(Ushahidi\App\Formatter\Post::class),
	'savedsearches_posts'  => $di->lazyNew(Ushahidi\App\Formatter\Post::class),
	'users'                => $di->lazyNew(Ushahidi\App\Formatter\User::class),
	'notifications'        => $di->lazyNew(Ushahidi\App\Formatter\Notification::class),
	'webhooks'             => $di->lazyNew(Ushahidi\App\Formatter\Webhook::class),
	'contacts'             => $di->lazyNew(Ushahidi\App\Formatter\Contact::class),
	'csv'                  => $di->lazyNew(Ushahidi\App\Formatter\CSV::class),
	'roles'                => $di->lazyNew(Ushahidi\App\Formatter\Role::class),
	'permissions'          => $di->lazyNew(Ushahidi\App\Formatter\Permission::class),
	// Formatter for post exports. Defaults to CSV export
	'posts_export'         => $di->lazyNew(Ushahidi\App\Formatter\Post\CSV::class),
];

// Formatter parameters
$di->setter[Ushahidi\App\Formatter\Config::class]['setAuth'] = $di->lazyGet("authorizer.config");
$di->setter[Ushahidi\App\Formatter\CSV::class]['setAuth'] = $di->lazyGet("authorizer.csv");
$di->setter[Ushahidi\App\Formatter\Dataprovider::class]['setAuth'] = $di->lazyGet("authorizer.dataprovider");
$di->setter[Ushahidi\App\Formatter\Form::class]['setAuth'] = $di->lazyGet("authorizer.form");
$di->setter[Ushahidi\App\Formatter\Form\Attribute::class]['setAuth'] = $di->lazyGet("authorizer.form_attribute");
$di->setter[Ushahidi\App\Formatter\Form\Role::class]['setAuth'] = $di->lazyGet("authorizer.form_role");
$di->setter[Ushahidi\App\Formatter\Form\Stage::class]['setAuth'] = $di->lazyGet("authorizer.form_stage");
$di->setter[Ushahidi\App\Formatter\Layer::class]['setAuth'] = $di->lazyGet("authorizer.layer");
$di->setter[Ushahidi\App\Formatter\Media::class]['setAuth'] = $di->lazyGet("authorizer.media");
$di->setter[Ushahidi\App\Formatter\Message::class]['setAuth'] = $di->lazyGet("authorizer.message");
$di->setter[Ushahidi\App\Formatter\Post::class]['setAuth'] = $di->lazyGet("authorizer.post");
$di->setter[Ushahidi\App\Formatter\Tag::class]['setAuth'] = $di->lazyGet("authorizer.tag");
$di->setter[Ushahidi\App\Formatter\User::class]['setAuth'] = $di->lazyGet("authorizer.user");
$di->setter[Ushahidi\App\Formatter\Savedsearch::class]['setAuth'] = $di->lazyGet("authorizer.savedsearch");
$di->setter[Ushahidi\App\Formatter\Set::class]['setAuth'] = $di->lazyGet("authorizer.set");
$di->setter[Ushahidi\App\Formatter\Set\Post::class]['setAuth'] = $di->lazyGet("authorizer.set_post");
$di->setter[Ushahidi\App\Formatter\Notification::class]['setAuth'] = $di->lazyGet("authorizer.notification");
$di->setter[Ushahidi\App\Formatter\Webhook::class]['setAuth'] = $di->lazyGet("authorizer.webhook");
$di->setter[Ushahidi\App\Formatter\Contact::class]['setAuth'] = $di->lazyGet("authorizer.contact");
$di->setter[Ushahidi\App\Formatter\Role::class]['setAuth'] = $di->lazyGet("authorizer.role");
$di->setter[Ushahidi\App\Formatter\Permission::class]['setAuth'] = $di->lazyGet("authorizer.permission");

// Set Formatter factory
$di->params['Ushahidi\Factory\FormatterFactory']['factory'] = $di->newFactory(Ushahidi\App\Formatter\Collection::class);


$di->set('tool.jsontranscode', $di->lazyNew('Ushahidi\Core\Tool\JsonTranscode'));

// Formatters
$di->set('formatter.entity.api', $di->lazyNew(Ushahidi\App\Formatter\API::class));
$di->set('formatter.entity.console', $di->lazyNew(Ushahidi\App\Formatter\Console::class));
$di->set('formatter.entity.post.value', $di->lazyNew(Ushahidi\App\Formatter\PostValue::class));
$di->set('formatter.entity.post.geojson', $di->lazyNew(Ushahidi\App\Formatter\Post\GeoJSON::class));
$di->set('formatter.entity.post.geojsoncollection', $di->lazyNew(Ushahidi\App\Formatter\Post\GeoJSONCollection::class));
$di->set('formatter.entity.post.stats', $di->lazyNew(Ushahidi\App\Formatter\Post\Stats::class));
$di->set('formatter.entity.post.csv', $di->lazyNew(Ushahidi\App\Formatter\Post\CSV::class));

$di->set('formatter.output.json', $di->lazyNew(Ushahidi\App\Formatter\JSON::class));
$di->set('formatter.output.jsonp', $di->lazyNew(Ushahidi\App\Formatter\JSONP::class));

// Formatter parameters
$di->setter[Ushahidi\App\Formatter\JSONP::class]['setCallback'] = function () {
	return Request::current()->query('callback');
};
$di->params[Ushahidi\App\Formatter\Post::class] = [
	'value_formatter' => $di->lazyGet('formatter.entity.post.value')
];
$di->setter[Ushahidi\App\Formatter\Post\GeoJSON::class]['setDecoder'] = $di->lazyNew('Symm\Gisconverter\Decoders\WKT');
$di->setter[Ushahidi\App\Formatter\Post\GeoJSONCollection::class]['setDecoder'] =
	$di->lazyNew('Symm\Gisconverter\Decoders\WKT');

// Repositories
$di->set('repository.config', $di->lazyNew(Ushahidi\App\Repository\ConfigRepository::class));
$di->set('repository.contact', $di->lazyNew(Ushahidi\App\Repository\ContactRepository::class));
$di->set('repository.dataprovider', $di->lazyNew(Ushahidi\App\Repository\DataproviderRepository::class));
$di->set('repository.form', $di->lazyNew(Ushahidi\App\Repository\FormRepository::class));
$di->set('repository.form_role', $di->lazyNew(Ushahidi\App\Repository\Form\RoleRepository::class));
$di->set('repository.form_stage', $di->lazyNew(Ushahidi\App\Repository\Form\StageRepository::class));
$di->set('repository.form_attribute', $di->lazyNew(Ushahidi\App\Repository\Form\AttributeRepository::class));
$di->set('repository.layer', $di->lazyNew(Ushahidi\App\Repository\LayerRepository::class));
$di->set('repository.media', $di->lazyNew(Ushahidi\App\Repository\MediaRepository::class));
$di->set('repository.message', $di->lazyNew(Ushahidi\App\Repository\MessageRepository::class));
$di->set('repository.post', $di->lazyNew(Ushahidi\App\Repository\PostRepository::class));
$di->set('repository.tag', $di->lazyNew(Ushahidi\App\Repository\TagRepository::class));
$di->set('repository.set', $di->lazyNew(Ushahidi\App\Repository\SetRepository::class));
$di->set('repository.savedsearch', $di->lazyNew(
	Ushahidi\App\Repository\SetRepository::class,
	[],
	[
		'setSavedSearch' => true
	]
));
$di->set('repository.user', $di->lazyNew(Ushahidi\App\Repository\UserRepository::class));
$di->set('repository.role', $di->lazyNew(Ushahidi\App\Repository\RoleRepository::class));
$di->set('repository.notification', $di->lazyNew(Ushahidi\App\Repository\NotificationRepository::class));
$di->set('repository.webhook', $di->lazyNew(Ushahidi\App\Repository\WebhookRepository::class));
$di->set('repository.csv', $di->lazyNew(Ushahidi\App\Repository\CSVRepository::class));
$di->set('repository.notification.queue', $di->lazyNew(Ushahidi\App\Repository\Notification\QueueRepository::class));
$di->set('repository.webhook.job', $di->lazyNew(Ushahidi\App\Repository\Webhook\JobRepository::class));
$di->set('repository.permission', $di->lazyNew(Ushahidi\App\Repository\PermissionRepository::class));
// $di->set('repository.oauth.client', $di->lazyNew('OAuth2_Storage_Client'));
// $di->set('repository.oauth.session', $di->lazyNew('OAuth2_Storage_Session'));
// $di->set('repository.oauth.scope', $di->lazyNew('OAuth2_Storage_Scope'));
$di->set('repository.posts_export', $di->lazyNew(Ushahidi\App\Repository\Post\ExportRepository::class));

$di->setter[Ushahidi\App\Repository\UserRepository::class]['setHasher'] = $di->lazyGet('tool.hasher.password');

// Repository parameters

// Abstract repository parameters
$di->params[Ushahidi\App\Repository\OhanzeeRepository::class] = [
	'db' => $di->lazyGet('kohana.db'),
	];

// Set up Json Transcode Repository Trait
$di->setter[Ushahidi\App\Repository\JsonTranscodeRepository::class]['setTranscoder'] =
	$di->lazyGet('tool.jsontranscode');

// Media repository parameters
$di->params[Ushahidi\App\Repository\MediaRepository::class] = [
	'upload' => $di->lazyGet('tool.uploader'),
	];

// Form Stage repository parameters
$di->params[Ushahidi\App\Repository\Form\StageRepository::class] = [
		'form_repo' => $di->lazyGet('repository.form')
];

// Form Attribute repository parameters
$di->params[Ushahidi\App\Repository\Form\AttributeRepository::class] = [
		'form_stage_repo' => $di->lazyGet('repository.form_stage'),
		'form_repo' => $di->lazyGet('repository.form')
];

// Post repository parameters
$di->params[Ushahidi\App\Repository\PostRepository::class] = [
		'form_attribute_repo' => $di->lazyGet('repository.form_attribute'),
		'form_stage_repo' => $di->lazyGet('repository.form_stage'),
		'form_repo' => $di->lazyGet('repository.form'),
		'post_value_factory' => $di->lazyGet('repository.post_value_factory'),
		'bounding_box_factory' => $di->newFactory(Ushahidi\App\Util\BoundingBox::class)
	];

$di->set('repository.post.datetime', $di->lazyNew(Ushahidi\App\Repository\Post\DatetimeRepository::class));
$di->set('repository.post.decimal', $di->lazyNew(Ushahidi\App\Repository\Post\DecimalRepository::class));
$di->set('repository.post.geometry', $di->lazyNew(Ushahidi\App\Repository\Post\GeometryRepository::class));
$di->set('repository.post.int', $di->lazyNew(Ushahidi\App\Repository\Post\IntRepository::class));
$di->set('repository.post.point', $di->lazyNew(Ushahidi\App\Repository\Post\PointRepository::class));
$di->set('repository.post.relation', $di->lazyNew(Ushahidi\App\Repository\Post\RelationRepository::class));
$di->set('repository.post.text', $di->lazyNew(Ushahidi\App\Repository\Post\TextRepository::class));
$di->set('repository.post.description', $di->lazyNew(Ushahidi\App\Repository\Post\DescriptionRepository::class));
$di->set('repository.post.varchar', $di->lazyNew(Ushahidi\App\Repository\Post\VarcharRepository::class));
$di->set('repository.post.markdown', $di->lazyNew(Ushahidi\App\Repository\Post\MarkdownRepository::class));
$di->set('repository.post.title', $di->lazyNew(Ushahidi\App\Repository\Post\TitleRepository::class));
$di->set('repository.post.media', $di->lazyNew(Ushahidi\App\Repository\Post\MediaRepository::class));
$di->set('repository.post.tags', $di->lazyNew(Ushahidi\App\Repository\Post\TagsRepository::class));

$di->params[Ushahidi\App\Repository\Post\TagsRepository::class] = [
    'tag_repo' => $di->lazyGet('repository.tag')
];

// The post value repo factory
$di->set('repository.post_value_factory', $di->lazyNew(Ushahidi\App\Repository\Post\ValueFactory::class));
$di->params[Ushahidi\App\Repository\Post\ValueFactory::class] = [
		// a map of attribute types to repositories
		'map' => [
			'datetime' => $di->lazyGet('repository.post.datetime'),
			'decimal'  => $di->lazyGet('repository.post.decimal'),
			'geometry' => $di->lazyGet('repository.post.geometry'),
			'int'      => $di->lazyGet('repository.post.int'),
			'point'    => $di->lazyGet('repository.post.point'),
			'relation' => $di->lazyGet('repository.post.relation'),
			'text'     => $di->lazyGet('repository.post.text'),
			'description' => $di->lazyGet('repository.post.description'),
			'varchar'  => $di->lazyGet('repository.post.varchar'),
			'markdown'  => $di->lazyGet('repository.post.markdown'),
			'title'    => $di->lazyGet('repository.post.title'),
			'media'    => $di->lazyGet('repository.post.media'),
			'tags'     => $di->lazyGet('repository.post.tags'),
		],
	];

$di->params[Ushahidi\App\Repository\Post\PointRepository::class] = [
	'decoder' => $di->lazyNew('Symm\Gisconverter\Decoders\WKT')
	];

// Validators
$di->set('validator.user.login', $di->lazyNew(Ushahidi\App\Validator\User\Login::class));
$di->set('validator.contact.create', $di->lazyNew(Ushahidi\App\Validator\Contact\Create::class));
$di->set('validator.contact.receive', $di->lazyNew(Ushahidi\App\Validator\Contact\Receive::class));

$di->params[Ushahidi\App\Validator\Contact\Update::class] = [
	'repo' => $di->lazyGet('repository.user'),
];

$di->params[Ushahidi\App\Validator\Config\Update::class] = [
	'available_providers' => $di->lazyGet('features.data-providers'),
];

// Dependencies of validators
$di->params[Ushahidi\App\Validator\Post\Create::class] = [
	'repo' => $di->lazyGet('repository.post'),
	'attribute_repo' => $di->lazyGet('repository.form_attribute'),
	'stage_repo' => $di->lazyGet('repository.form_stage'),
	'tag_repo' => $di->lazyGet('repository.tag'),
	'user_repo' => $di->lazyGet('repository.user'),
	'form_repo' => $di->lazyGet('repository.form'),
	'role_repo' => $di->lazyGet('repository.role'),
	'post_value_factory' => $di->lazyGet('repository.post_value_factory'),
	'post_value_validator_factory' => $di->lazyGet('validator.post.value_factory'),
	'limits' => $di->lazyGet('features.limits'),
	];

$di->params[Ushahidi\App\Validator\Form\Update::class] = [
	'repo' => $di->lazyGet('repository.form'),
	'limits' => $di->lazyGet('features.limits'),
	];

$di->param[Ushahidi\App\Validator\Form\Attribute\Update::class] = [
	'repo' => $di->lazyGet('repository.form_attribute'),
	'form_stage_repo' => $di->lazyGet('repository.form_stage'),
];
$di->params[Ushahidi\App\Validator\Layer\Update::class] = [
	'media_repo' => $di->lazyGet('repository.media'),
];
$di->params[Ushahidi\App\Validator\Message\Update::class] = [
	'repo' => $di->lazyGet('repository.message'),
];
$di->params[Ushahidi\App\Validator\Message\Create::class] = [
	'repo' => $di->lazyGet('repository.message'),
	'user_repo' => $di->lazyGet('repository.user')
];

$di->params[Ushahidi\App\Validator\Message\Receive::class] = [
	'repo' => $di->lazyGet('repository.message'),
];

$di->params[Ushahidi\App\Validator\Set\Update::class] = [
	'repo' => $di->lazyGet('repository.user'),
	'role_repo' => $di->lazyGet('repository.role'),
];
$di->params[Ushahidi\App\Validator\Notification\Update::class] = [
	'user_repo' => $di->lazyGet('repository.user'),
	'collection_repo' => $di->lazyGet('repository.set'),
	'savedsearch_repo' => $di->lazyGet('repository.savedsearch'),
];
$di->params[Ushahidi\App\Validator\Webhook\Update::class] = [
	'user_repo' => $di->lazyGet('repository.user'),
];
$di->params[Ushahidi\App\Validator\SavedSearch\Create::class] = [
	'repo' => $di->lazyGet('repository.user'),
	'role_repo' => $di->lazyGet('repository.role'),
];
$di->params[Ushahidi\App\Validator\SavedSearch\Update::class] = [
	'repo' => $di->lazyGet('repository.user'),
	'role_repo' => $di->lazyGet('repository.role'),
];

$di->params[Ushahidi\App\Validator\Set\Post\Create::class] = [
	'post_repo' => $di->lazyGet('repository.post')
];

$di->params[Ushahidi\App\Validator\Tag\Update::class] = [
	'repo' => $di->lazyGet('repository.tag'),
	'role_repo' => $di->lazyGet('repository.role'),
];

$di->params[Ushahidi\App\Validator\User\Update::class] = [
	'repo' => $di->lazyGet('repository.user'),
	'role_repo' => $di->lazyGet('repository.role'),
	'limits' => $di->lazyGet('features.limits'),
];
$di->params[Ushahidi\App\Validator\User\Register::class] = [
	'repo'    => $di->lazyGet('repository.user')
];
$di->params[Ushahidi\App\Validator\CSV\Create::class] = [
	'form_repo' => $di->lazyGet('repository.form'),
];
$di->params[Ushahidi\App\Validator\CSV\Update::class] = [
	'form_repo' => $di->lazyGet('repository.form'),
];
$di->params[Ushahidi\App\Validator\Role\Update::class] = [
	'permission_repo' => $di->lazyGet('repository.permission'),
];

// Validator Setters
$di->setter[Ushahidi\App\Validator\Form\Stage\Update::class] = [
	'setFormRepo' => $di->lazyGet('repository.form'),
];
$di->setter[Ushahidi\App\Validator\Form\Role\Update::class] = [
	'setFormRepo' => $di->lazyGet('repository.form'),
	'setRoleRepo' => $di->lazyGet('repository.role'),
];
$di->setter[Ushahidi\App\Validator\Media\Create::class] = [
	'setMaxBytes' => $di->lazy(function () {
		return \Kohana::$config->load('media.max_upload_bytes');
	}),
];
$di->setter[Ushahidi\App\Validator\CSV\Create::class] = [
	// @todo load from config
	'setMaxBytes' => '2048000',
];


$di->set('validator.post.datetime', $di->lazyNew(Ushahidi\App\Validator\Post\Datetime::class));
$di->set('validator.post.decimal', $di->lazyNew(Ushahidi\App\Validator\Post\Decimal::class));
$di->set('validator.post.geometry', $di->lazyNew(Ushahidi\App\Validator\Post\Geometry::class));
$di->set('validator.post.int', $di->lazyNew(Ushahidi\App\Validator\Post\Int::class));
$di->set('validator.post.link', $di->lazyNew(Ushahidi\App\Validator\Post\Link::class));
$di->set('validator.post.point', $di->lazyNew(Ushahidi\App\Validator\Post\Point::class));
$di->set('validator.post.relation', $di->lazyNew(Ushahidi\App\Validator\Post\Relation::class));
$di->set('validator.post.varchar', $di->lazyNew(Ushahidi\App\Validator\Post\Varchar::class));
$di->set('validator.post.markdown', $di->lazyNew(Ushahidi\App\Validator\Post\Markdown::class));
$di->set('validator.post.video', $di->lazyNew(Ushahidi\App\Validator\Post\Video::class));
$di->set('validator.post.title', $di->lazyNew(Ushahidi\App\Validator\Post\Title::class));
$di->set('validator.post.media', $di->lazyNew(Ushahidi\App\Validator\Post\Media::class));
$di->params[Ushahidi\App\Validator\Post\Media::class] = [
	'media_repo' => $di->lazyGet('repository.media')
];
$di->set('validator.post.tags', $di->lazyNew(Ushahidi\App\Validator\Post\Tags::class));
$di->params[Ushahidi\App\Validator\Post\Tags::class] = [
    'tags_repo' => $di->lazyGet('repository.tag')
];


$di->set('validator.post.value_factory', $di->lazyNew(Ushahidi\App\Validator\Post\ValueFactory::class));
$di->params[Ushahidi\App\Validator\Post\ValueFactory::class] = [
		// a map of attribute types to validators
		'map' => [
			'datetime' => $di->lazyGet('validator.post.datetime'),
			'decimal'  => $di->lazyGet('validator.post.decimal'),
			'geometry' => $di->lazyGet('validator.post.geometry'),
			'int'      => $di->lazyGet('validator.post.int'),
			'link'     => $di->lazyGet('validator.post.link'),
			'point'    => $di->lazyGet('validator.post.point'),
			'relation' => $di->lazyGet('validator.post.relation'),
			'varchar'  => $di->lazyGet('validator.post.varchar'),
			'markdown' => $di->lazyGet('validator.post.markdown'),
			'title'    => $di->lazyGet('validator.post.title'),
			'media'    => $di->lazyGet('validator.post.media'),
			'video'    => $di->lazyGet('validator.post.video'),
            'tags'     => $di->lazyGet('validator.post.tags'),
		],
	];

$di->params[Ushahidi\App\Validator\Post\Relation::class] = [
	'repo' => $di->lazyGet('repository.post')
	];

$di->set('transformer.mapping', $di->lazyNew(Ushahidi\App\Transformer\MappingTransformer::class));
$di->set('transformer.csv', $di->lazyNew(Ushahidi\App\Transformer\CSVPostTransformer::class));
// Post repo for mapping transformer
$di->setter[Ushahidi\App\Transformer\CSVPostTransformer::class]['setRepo'] =
	$di->lazyGet('repository.post');

// Event listener for the Set repo
$di->setter[Ushahidi\App\Repository\SetRepository::class]['setEvent'] = 'PostSetEvent';

$di->setter[Ushahidi\App\Repository\SetRepository::class]['setListener'] =
	$di->lazyNew(Ushahidi\App\Listener\PostSetListener::class);

// NotificationQueue repo for Set listener
$di->setter[Ushahidi\App\Listener\PostSetListener::class]['setRepo'] =
	$di->lazyGet('repository.notification.queue');

// Event listener for the Post repo
$di->setter[Ushahidi\App\Repository\PostRepository::class]['setEvent'] = 'PostCreateEvent';
$di->setter[Ushahidi\App\Repository\PostRepository::class]['setListener'] =
	$di->lazyNew(Ushahidi\App\Listener\PostListener::class);

// WebhookJob repo for Post listener
$di->setter[Ushahidi\App\Listener\PostListener::class]['setRepo'] =
	$di->lazyGet('repository.webhook.job');

// Webhook repo for Post listener
$di->setter[Ushahidi\App\Listener\PostListener::class]['setWebhookRepo'] =
	$di->lazyGet('repository.webhook');

// Add Intercom Listener to Config
$di->setter[Ushahidi\App\Repository\ConfigRepository::class]['setEvent'] = 'ConfigUpdateEvent';
$di->setter[Ushahidi\App\Repository\ConfigRepository::class]['setListener'] =
	$di->lazyNew(Ushahidi\App\Listener\IntercomListener::class);

// Add Intercom Listener to Form
$di->setter[Ushahidi\App\Repository\FormRepository::class]['setEvent'] = 'FormUpdateEvent';
$di->setter[Ushahidi\App\Repository\FormRepository::class]['setListener'] =
	$di->lazyNew(Ushahidi\App\Listener\IntercomListener::class);

// Add Intercom Listener to User
$di->setter[Ushahidi\App\Repository\UserRepository::class]['setEvent'] = 'UserGetAllEvent';
$di->setter[Ushahidi\App\Repository\UserRepository::class]['setListener'] =
	$di->lazyNew(Ushahidi\App\Listener\IntercomListener::class);