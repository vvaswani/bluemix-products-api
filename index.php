<?php
// set up Composer autoloader
require __DIR__ . '/vendor/autoload.php';

// use Eloquent ORM
use Illuminate\Database\Capsule\Manager as Capsule;  
use Illuminate\Database\Schema\Blueprint as Schema;  
 
// create model for Eloquent ORM mapped to REST API resource
class Product extends Illuminate\Database\Eloquent\Model {
  public $timestamps = false;
}

function convert_array_to_xml($data) {
  $xml = new SimpleXMLElement('<root/>');
  foreach ($data as $r) {
    $item = $xml->addChild('product');
    $item->addChild('id', $r['id']);
    $item->addChild('name', $r['name']);
    $item->addChild('price', $r['price']); 
  }
  return $xml->asXml();
}

// get MySQL service configuration from BlueMix
$services = getenv("VCAP_SERVICES");
$services_json = json_decode($services, true);
$mysql_config = $services_json["mysql-5.5"][0]["credentials"];
$db = $mysql_config["name"];
$host = $mysql_config["host"];
$port = $mysql_config["port"];
$username = $mysql_config["user"];
$password = $mysql_config["password"];

// initialize Eloquent ORM
$capsule = new Capsule;

// use for BlueMix development
$capsule->addConnection(array(
    'driver'    => 'mysql',
    'host'      => $host,
    'port'      => $port,
    'database'  => $db,
    'username'  => $username,
    'password'  => $password,
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix'    => ''
));

// use for local development
/*
$capsule->addConnection(array(
  'driver'    => 'mysql',
  'host'      => 'localhost',
  'database'  => 'test',
  'username'  => 'root',
  'password'  => '',
  'charset'   => 'utf8',
  'collation' => 'utf8_unicode_ci',
  'prefix'    => ''
));
*/


$capsule->setAsGlobal(); 
$capsule->bootEloquent();

// initialize application
$app = new Bullet\App();

// global 'Exception' handler event
$app->on('Exception', function($request, $response, Exception $e) use ($app) {
  // send 500 error with JSON info about exception
  $response->status(500);
  $response->content(array(
    'exception' => get_class($e),
    'message' => $e->getMessage()
  ));
});

$app->path('v1', function($request) use ($app) {

  $app->path('products', function($request) use ($app) {

    // trigger authentication event
    // uncomment to see authentication in action
    // $app->filter('auth');  	
  	
    // GET /v1/products[.xml|.json]
    // list all products
    $app->get(function() use ($app)  {

      $products = Product::all();         
      
      // handle requests for XML content
      $app->format('xml', function($request) use($app, $products) {
        return $app->response(200, convert_array_to_xml($products->toArray()))
                      ->header('Content-Type', 'application/xml');
      }); 
      
      // handle requests for JSON content
      $app->format('json', function($request) use($app, $products) {
        return $products->toArray();
      });  
        
    });
    
    // POST /v1/products[.xml|.json]
    // create new product
    $app->post(function($request) use ($app) {

      // handle requests for XML content
      $app->format('xml', function($request) use($app) {
        $input = simplexml_load_string($request->raw());
        $product = new Product();
        $product->name = trim(htmlentities((string)$input->name));
        $product->price = round(trim(htmlentities((string)$input->price)), 2);
        if ($product->name && $product->price) {
          $product->save();
          return $app->response(201, convert_array_to_xml(array($product->toArray())))
                      ->header('Content-Type', 'application/xml');          
        } else {
          return 400;
        }
      });
      
      // handle requests for JSON content
      $app->format('json', function($request) use($app) {          
        $product = new Product();
        $product->name = trim(htmlentities($request->name));
        $product->price = round(trim(htmlentities($request->price)), 2);
        if ($product->name && $product->price) {
          $product->save();
          return $app->response(201, $product->toArray());
        } else {
          return 400;
        }
      }); 
      
    });    
      
    $app->param('int', function($request, $id) use($app) {
      $product = Product::find($id);

      if(!$product) {
        return 404;
      } 
      
      // GET /v1/products/:id
      // list single product by id
      $app->get(function($request) use($product, $app) {
        return $product->toArray();
      });
      
      // PUT /v1/products/:id
      // update product by id
      $app->put(function($request) use($product, $app) {
        $product->name = trim(htmlentities($request->name));
        $product->price = round(trim(htmlentities($request->price)), 2);
        if ($product->name && $product->price) {
          $product->save();
          return $app->response(200, $product->toArray());
        } else {
          return 400;          
        }
      });
        
      // DELETE /v1/products/:id
      // delete product by id
      $app->delete(function($request) use($product) {
        $product->delete();
        return 204;
      });
          
    });        
    
  });    
  
});

// set cookies
$app->path('set-auth-cookies', function($request) use ($app) {

  $app->get(function() use ($app)  {
    setcookie('uid', 'demo');
    setcookie('pass', 'demo');
    return 200;
  });
  
});

// delete cookies
$app->path('unset-auth-cookies', function($request) use ($app) {

  $app->get(function($request) use ($app)  {
    setcookie('uid', '', time()-3600);
    setcookie('pass', '', time()-3600);
    return 200;
  });
  
});

// runs when 'auth' event is triggered on protected API routes to check that
// credentials (here, plaintext cookies) are present 
// replace with more complex authentication function in production
$app->on('auth', function($request, $response) use ($app) {

  if (!$request->cookie('uid') == 'demo' || !$request->cookie('pass') == 'demo') {
    $response->status(401);
    $response->send();
    exit;
  }
  
});

// initialize MySQL database schema and insert sample records
// alternatively, use products.sql file
$app->path('install-schema', function($request) use ($app) {

  Capsule::schema()->drop('products');

  Capsule::schema()->create('products', function($table)
  {
    $table->increments('id');
    $table->string('name', 255);
    $table->decimal('price', 5, 2);
  });
  
  $product = new Product();
  $product->id = '1';
  $product->name = 'Garden spade';
  $product->price = '15.99';
  $product->save();

  $product = new Product();
  $product->id = '2';
  $product->name = 'Cotton hammock';
  $product->price = '54.50';
  $product->save();

  $product = new Product();
  $product->id = '3';
  $product->name = 'Single airbed';
  $product->price = '35.49';
  $product->save();    
});


echo $app->run(new Bullet\Request());