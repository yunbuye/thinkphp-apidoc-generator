<?php

namespace Xwpd\ThinkApiDoc\Generators;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use think\Exception;
use think\facade\Config;
use think\Loader;

class ThinkphpGenerator extends AbstractGenerator
{
    /**
     * @param Route $route
     * @return mixed
     */
    public function getAction($route){
        if (is_callable($route['route']) || mb_strpos($route['route'], '@')) {
            //'\完整的命名空间类::静态方法' 或者 '\完整的命名空间类@动态方法'
            if (mb_strpos($route['route'], '::')) {
                $route['route'] = str_replace('::', '@', $route['route']);
            }
            $action['uses'] = $route['route'];
        } elseif (mb_strpos($route['route'], '@') === 0) {
            //	'@[模块/控制器/]操作'
            $route['route'] = str_replace('@', '', $route['route']);
            $action['uses'] = $this->findAction($route['route']);
        } else {
            $action['uses'] = $this->findAction($route['route']);
        }
        return $action;
    }

    /**
     * @param $route
     * @return string
     */
    protected function findAction($route){
        if (mb_strpos($route, '?')) {
            //'[模块/控制器/]操作?额外参数1=值1&额外参数2=值2...'
            $route = substr($route, 0, mb_strpos($route, '?') + 1);
        }
        //'[模块/控制器/操作]'
        $str = $this->paseMCA($route);
        return $str;
    }

    /**
     * [模块/控制器/操作]?额外参数1=值1&额外参数2=值2...
     * @return string
     */
    protected function paseMCA($route){
        $root_namespace = 'app\\';
        $default_module = Config::get('default_module');
        $default_controller = Config::get('default_controller');
        $default_action = Config::get('default_action');
        $url_controller_layer = Config::get('url_controller_layer');
        if (mb_strpos($route, '?') === 0) {
            //'?额外参数1=值1&额外参数2=值2...'
            $str = $root_namespace.$default_module.'\\'.$url_controller_layer.'\\'.ucfirst($default_controller).'@'.$default_action;
        } else {
            //[模块/控制器/操作]
            //'index/group.blog/read'
            $arr = explode('/', $route);
            if (count($arr) === 1) {
                $str = $root_namespace.$default_module.'\\'.$url_controller_layer.'\\'.ucfirst($default_controller).'@'.$arr[0];
            } elseif (count($arr) === 2) {
                $str = $root_namespace.$default_module.'\\'.$url_controller_layer.'\\'.ucfirst(str_replace('.', '\\', $arr[0])).'@'.$arr[1];
            } else {
                $str = $root_namespace.$arr[0].'\\'.$url_controller_layer.'\\'.ucfirst(str_replace('.', '\\', $arr[1])).'@'.$arr[2];
            }
        }
        return $str;
    }

    public function getName($route){
        return $route['name'];
    }

    /**
     * @param Route $route
     * @return mixed
     */
    public function getDomain($route){
        return $route['domain'];
    }

    /**
     * @param Route $route
     * @return mixed
     */
    public function getUri($route){
        return $route['rule'];
    }

    /**
     * @param Route $route
     * @return mixed
     */
    public function getMethods($route){
        return [$route['method']];
    }

    /**
     * Prepares / Disables route middlewares.
     * @param bool $disable
     * @return  void
     */
    public function prepareMiddleware($enable = true){
        // App::instance('middleware.disable', ! $enable);
    }

    /**
     * Call the given URI and return the Response.
     * @param string $method
     * @param string $uri
     * @param array $parameters
     * @param array $cookies
     * @param array $files
     * @param array $server
     * @param string $content
     * @return \Illuminate\Http\Response
     */
    public function callRoute($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null){
        $server = collect([
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ])->merge($server)->toArray();

        $request = Request::create(
            $uri, $method, $parameters,
            $cookies, $files, $this->transformHeadersToServerVars($server), $content
        );

        $kernel = App::make('Illuminate\Contracts\Http\Kernel');
        $response = $kernel->handle($request);

        $kernel->terminate($request, $response);

        return $response;
    }
}
