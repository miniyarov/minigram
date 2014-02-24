<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once 'parameters.php';

$app = new Silex\Application();

if (SILEX_ENV != 'prod') {
    // in depth debug info while not in production env.
    ini_set('xdebug.var_display_max_depth', 15);
    ini_set('xdebug.var_display_max_children', 256);
    ini_set('xdebug.var_display_max_data', 1024);

    $app['debug'] = true;
}

// Handling fatal errors -> http://silex.sensiolabs.org/doc/cookbook/error_handler.html#handling-fatal-errors
\Symfony\Component\Debug\ExceptionHandler::register($app['debug']);

$app['file_upload_path'] = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'photo' . DIRECTORY_SEPARATOR;

$twigParams = array(
    'twig.path' => __DIR__ . DIRECTORY_SEPARATOR . 'views'
);

// enable .twig file caches in production
if (!$app['debug']) {
    $twigParams['twig.options'] = array(
        'cache' => __DIR__ . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'cache'
    );
}

$app->register(new Silex\Provider\TwigServiceProvider(), $twigParams);

$app->register(new SilexAssetic\AsseticServiceProvider());

$app->register(new \Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__ . DIRECTORY_SEPARATOR . 'monolog.log',
    'monolog.name' => 'minigram'
));

$app['assetic.path_to_web'] = __DIR__ . DIRECTORY_SEPARATOR . 'assets';

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addExtension(new \Entea\Twig\Extension\AssetExtension($app));
    $twig->addExtension(new \Twig_Extensions_Extension_Text());
    return $twig;
}));

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app->register(new Silex\Provider\SessionServiceProvider());

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver' => DB_DRIVER,
        'dbname' => DB_NAME,
        'host' => DB_HOST,
        'user' => DB_USER,
        'password' => DB_PASS,
        'charset' => 'utf8'
    )
));

$app['db_repo'] = new DbRepository($app['db']);

class DbRepository
{
    /** @var \Doctrine\DBAL\Connection $db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getPictures($limit = 30)
    {
        return $this->db->fetchAll('SELECT * FROM pictures ORDER BY created_at DESC LIMIT '. (int) $limit);
    }

    public function getPictureById($id)
    {
        return $this->db->fetchAssoc('SELECT * FROM pictures WHERE id = ?', array($id));
    }

    public function updatePicturePrintStatus($id)
    {
        $datetime = date('Y-m-d H:i:s');
        $updated = $this->db->update('pictures', array('is_printed' => 1, 'printed_at' => $datetime), array('id' => $id));

        return $updated > 0 ? $datetime : null;
    }

    public function getActiveTags()
    {
        return $this->db->fetchAll('SELECT * FROM tags WHERE is_active = 1');
    }

    public function getMinTagId()
    {
        // @todo
    }

    public function getMaxTagId()
    {
        // @todo
    }
}