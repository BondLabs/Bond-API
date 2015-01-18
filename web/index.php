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

function isauthkey($id, $key, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE auth_key=:key');
    $st->execute(array(':key' => $key));
    $uid = $st->fetch(PDO::FETCH_ASSOC);
    //$uid = $uid[0]; 
    
    $app['monolog']->addDebug("PDO RESULT: ".$uid);
    
    if($id === $uid){
        return true; 
    }
    return false;
}

$auth = function(Request $request) use($app) {
    $auth = $request->headers->get('x-auth-key');
    $passeduid = $request->getRequestUri();
    $passeduid = explode("/", $passeduid);
    $id = $passeduid[3]; 

    if(isauthkey($id, $auth, $app)){
        $app['monolog']->addDebug("they match");
    } else {
        $app['monolog']->addDebug("they don't match");
    }
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
    $name = $post['name'];
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
