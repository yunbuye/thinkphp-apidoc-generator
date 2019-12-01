# ThinkPHP api文档自动生成
## 支持  
   thinkphp5.1.*
   
## 安装
1. 安装依赖
    ```bash
    composer require xwpd/thinkphp-apidoc-generator --dev
    ```
1. 添加命令     
    打开配置文件 application/command.php 添加如下内容
    ```php
    return [
        //...其他命令
        "api-doc:generate"=>Xwpd\ThinkApiDoc\Commands\GenerateDocumentation::class
    ];
    ```
   
## 使用 
1. 运行命令 生成文档
   ```bash
    php think api-doc:generate --routePrefix=*
   ```
1.  其他参数   
    运行下面命令查看
    ```bash
        php think api-doc:generate -h
    ```
    
## 功能
1. 分组
    * 在控制器头部添加注释 @resource  拥有下面注释会分到 例子 的这个组里
    ```php
    /**
   * @resource 例子
   * 其他描述备注
    */
    ```
   
1. 请求参数自动生成   
   （未做）在控制器操作里，依赖注入请求验证，会自动生成参数文档
 
## Thinks
   https://github.com/mpociot/laravel-apidoc-generator
    
## License
   MIT license.