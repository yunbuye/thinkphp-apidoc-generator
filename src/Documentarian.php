<?php

namespace Xwpd\ThinkApiDoc;

use Mni\FrontYAML\Parser;
use Xwpd\ThinkApiDoc\Commands\GenerateDocumentation;

/**
 * Class Documentarian
 * @package Mpociot\Documentarian
 */
class Documentarian
{

    /**
     * Returns a config value
     * @param string $key
     * @return mixed
     */
    public function config($folder, $key = null){
        $config = include($folder.'/source/config.php');

        return is_null($key)? $config : array_get($config, $key);
    }

    /**
     * Create a new API documentation folder and copy all needed files/stubs
     * @param $folder
     */
    public function create($folder){
        $folder = $folder.'/source';
        $resources_dir = __DIR__.'/../resources/';
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
            mkdir($folder.'/../css');
            mkdir($folder.'/../js');
            mkdir($folder.'/includes');
            mkdir($folder.'/assets');
        }

        // copy stub files
        copy($resources_dir.'stubs/index.md', $folder.'/index.md');
        copy($resources_dir.'stubs/gitignore.stub', $folder.'/.gitignore');
        copy($resources_dir.'stubs/includes/_errors.md', $folder.'/includes/_errors.md');
        copy($resources_dir.'stubs/package.json', $folder.'/package.json');
        copy($resources_dir.'stubs/gulpfile.js', $folder.'/gulpfile.js');
        copy($resources_dir.'stubs/config.php', $folder.'/config.php');
        copy($resources_dir.'stubs/js/all.js', $folder.'/../js/all.js');
        copy($resources_dir.'stubs/css/style.css', $folder.'/../css/style.css');

        // copy resources
        $this->rcopy($resources_dir.'images/', $folder.'/assets/images');
        $this->rcopy($resources_dir.'js/', $folder.'/assets/js');
        $this->rcopy($resources_dir.'stylus/', $folder.'/assets/stylus');
    }

    /**
     * Generate the API documentation using the markdown and include files
     * @param $folder
     * @return false|null
     */
    public function generate($folder){
        $source_dir = $folder.'/source';

        if (!is_dir($source_dir)) {
            return false;
        }

        $parser = new Parser();

        $document = $parser->parse(file_get_contents($source_dir.'/index.md'));

        $frontmatter = $document->getYAML();
        $html = $document->getContent();

        // Parse and include optional include markdown files
        if (isset($frontmatter['includes'])) {
            foreach ($frontmatter['includes'] as $include) {
                if (file_exists($include_file = $source_dir.'/includes/_'.$include.'.md')) {
                    $document = $parser->parse(file_get_contents($include_file));
                    $html .= $document->getContent();
                }
            }
        }

        $output = GenerateDocumentation::view(DIRECTORY_SEPARATOR.'index', [
            'page' => $frontmatter,
            'content' => $html
        ]);


        file_put_contents($folder.'/index.html', $output);

        // Copy assets
        $this->rcopy($source_dir.'/assets/images/', $folder.'/images');
        $this->rcopy($source_dir.'/assets/stylus/fonts/', $folder.'/css/fonts');
    }

    protected function rcopy($src, $dest){

        // If source is not a directory stop processing
        if (!is_dir($src)) return false;

        // If the destination directory does not exist create it
        if (!is_dir($dest)) {
            if (!mkdir($dest)) {
                // If the destination directory could not be created stop processing
                return false;
            }
        }

        // Open the source directory to read in files
        $i = new \DirectoryIterator($src);
        foreach ($i as $f) {
            if ($f->isFile()) {
                copy($f->getRealPath(), "$dest/".$f->getFilename());
            } else if (!$f->isDot() && $f->isDir()) {
                $this->rcopy($f->getRealPath(), "$dest/$f");
            }
        }
    }
}