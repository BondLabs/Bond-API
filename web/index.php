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

$auth = function(Request $request) use($app) {
    $auth = $request->headers->get('auth_key');
    
    $app['monolog']->addDebug("AUTH KEY:".$auth);
};

$app->get('/api/images/{id}', function($id) use($app) {
    
    if( $id < 1 || empty($var) ){
        return $app->json(array("error" => "Please provide a valid identification number."), 400);
    }
    
    $st = $app['pdo']->prepare('SELECT file FROM images WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $app->json($row, 200); 
})
-> before($auth); 

$app->post('/api/users', function() use($app) {
    $id = $post['id'];
    $name = $post['nane'];
    $email = $post['email'];
    $phone = $post['password'];
    $age = $post['age'];
    $gender = $post['gender'];

    if( empty($id) ) {
        // create new user
        
    } else {
        // update existing user
    }  
}); 

$app->get('/db/', function() use($app) {
    return 'db endpoint';  

});

$app->run();

?>
