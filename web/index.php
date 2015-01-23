<?php


require('../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;

// Validation class
use Respect\Validation\Validator as v;

$app = new Silex\Application();
$app['debug'] = true;


// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$dbopts = parse_url(getenv('DATABASE_URL'));

if(getenv('DATABASE_URL')) {
    $app->register(new Herrera\Pdo\PdoServiceProvider(),
      array(
          'pdo.dsn' => 'pgsql:dbname='.ltrim($dbopts["path"],'/').';host='.$dbopts["host"],
          'pdo.port' => $dbopts["port"],
          'pdo.username' => $dbopts["user"],
          'pdo.password' => $dbopts["pass"]
      )
    );
} else {
    $app->register(new Herrera\Pdo\PdoServiceProvider(),
      array(
          'pdo.dsn' => 'pgsql:dbname=bond;host=localhost',
          'pdo.port' => 5432,
          'pdo.username' => "misbahkhan",
          'pdo.password' => ""
      )
    );
}

// Our web handlers

$app->get('/', function() use($app) {
    $app['monolog']->addDebug('logging output.');
    return 'Hello';
});

$app->get('/api/', function(Request $request) use($app) {
    return $app->json($request->headers->all(), 200); 
});

function isauthkey($id, $key, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE auth_key=:key');
    $st->execute(array(':key' => $key));
    $res = $st->fetch(PDO::FETCH_ASSOC);
    $uid = $res['id']; 

    if(intval($id) === intval($uid)){
        return true; 
    }
    return false;
}

$auth = function(Request $request) use($app) {
    $auth = $request->headers->get('x-auth-key');
    $passeduid = $request->getRequestUri();
    $passeduid = explode("/", $passeduid);
    $id = $passeduid[3]; 

    if(!isauthkey($id, $auth, $app)){
        return $app->json(array("error" => "Invalid authorization key."), 401);
    }

    if( $id < 1 || empty($id) ){
        return $app->json(array("error" => "Please provide a valid identification number."), 400);
    }
    
    if(empty($auth)) {
        return $app->json(array("error" => "Authorization key is missing."), 403);     
    }
};

$app->get('/api/images/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('SELECT file FROM images WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "No image was found for the given identification number."), 400); 
    }
    
    return $app->json($row, 200); 
})
-> before($auth); 

$app->delete('/api/images/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('DELETE FROM images WHERE id=:id');
    $st->execute(array(':id' => $id));

    if($st->rowCount() > 0) {
        return $app->json(array("error" => "No image was found with the given identification number."), 412);
    } else {
        return $app->json(array("message" => "success"), 200); 
    }

    return $app->json(array("error" => "Something went wrong.  Please try again later."), 500);
})->before($auth); 

function doesexist($email, $app) {
    $st = $app['pdo']->prepare('SELECT id FROM users WHERE email=:email');
    $st->execute(array(':email' => $email));
    $res = $st->fetch(PDO::FETCH_ASSOC);
    if($st->rowCount() > 0) {
        return true;
    }
    return false;
}

$app->delete('/api/users/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('DELETE FROM users WHERE id=:id');
    $st->execute(array(':id' => $id));
    return $app->json(array("message" => "success"), 200); 
})->before($auth); 

$app->get('/api/users/{id}', function($id) use($app) {
    $st = $app['pdo']->prepare('SELECT * FROM users WHERE id=:id');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    
    unset($row["auth_key"]); 
    
    if(empty($row) || $st->rowCount() < 1){
        return $app->json(array("error" => "Please provide a valid identification number."), 400); 
    }
    
    return $app->json($row, 200); 
})->before($auth); 


// TODO: add auth middleware for following endpoint
$app->post('/api/users', function(Request $request) use($app) {
    $id = $request->get('id');
    $name = $request->get('name');
    $email = $request->get('email');
    $phone = $request->get('phone');
    $password = $request->get('password');
    $age = $request->get('age');
    $gender = $request->get('gender');

    $valid = array();
    $valid["name"] = v::string()->length(1,32)->validate($name);
    $valid["password"] = v::string()->length(1,64)->validate($password);
    $valid["email"] = v::email()->validate($email);
    $valid["phone"] = v::phone()->validate($phone); 
    $valid["age"] = v::numeric()->validate($age);

    $error = "";

    foreach($valid as $key=>$value) {
        if(!$valid[$key]) {
            $error.=$key." ";
        }
    }
    
    if(strlen($error) > 0) {
        return $app->json(array("error" => "Please provide a valid ".$error), 400);
    }

    if(empty($id)) {
        $app['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
        // new user might need to be created 
        // check if email exists
        if(doesexist($email, $app)) {
            return $app->json(array("error" => "An account with the given information already exists."), 409); 
        }
        
        // email does not exist, continue to create user 

        // hash the password
        $password = password_hash($password, PASSWORD_DEFAULT);
        
        // insert into the database 
        $st = $app['pdo']->prepare("INSERT INTO users(name, email, phone, age) VALUES(:name, :email, :phone, :age) RETURNING id");
        $st->execute(array('name' => $name, 'email' => $email, 'phone' => $phone, 'age' => $age));
        
        $insertedrow = $st->fetchAll(); 

        // $insertedrow[0][{param}] seems to work, hopefully wont break ever.
        // return success json
        return $app->json(array("success" => "New user created", "id" => $insertedrow[0]["id"]), 200);
    } else {
        // update existing user
        return $app->json(array("log" => "update existing user"), 200);
    }  
}); 

$app->run();

?>
