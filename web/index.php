<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;


$app = new Silex\Application();
$app['debug'] = true;


// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$dbopts = parse_url(getenv('DATABASE_URL'));
$app->register(new Herrera\Pdo\PdoServiceProvider(),
  array(
      'pdo.dsn' => 'pgsql:dbname='.ltrim($dbopts["path"],'/').';host='.$dbopts["host"],
      'pdo.port' => $dbopts["port"],
      'pdo.username' => $dbopts["user"],
      'pdo.password' => $dbopts["pass"]
  )
);


// Our web handlers

$app->get('/', function() use($app) {
    $app['monolog']->addDebug('logging output.');
    return 'Hello';
});

$app->get('/api/', function(Request $request) use($app) {
    return $app->json($request->headers->all(), 200); 
});

$app->post('/api/users', function() use($app) {
        
}); 

$app->delete('/api/users', function() use($app) {
    
}); 

$app->get('/api/images/{id}', function() use($app) {
    $st = $app['pdo']->prepare('SELECT file FROM images WHERE id=:id');
    
    $st->execute(array(':id' => $id);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $app->json($row, 200); 
}); 

$app->get('/db/', function() use($app) {
    return 'db endpoint';  

});

$app->run();

?>
