<?php
/*
To use this, you'll need to add this to app/config/sculpin_services.yml:

services:
    flickr_twig_extension:
        class: \FlickrTwigExtension
        tags:
            - { name: twig.extension }
        arguments:
            - %sculpin.output_dir%
            - %kernel.cache_dir%
            - (put your flickr api key here)


You'll also need to `require` this file from app/SculpinKernel.php
(and a stub kernel if you're not using one). See:
https://sculpin.io/documentation/extending-sculpin/configuration/

*/

class FlickrTwigExtension extends \Twig_Extension
{
    protected $output_dir;
    protected $cache_dir;
    protected $flickr_key;
    protected $debugMode;

    public function __construct($output_dir, $cache_dir, $flickr_key, $debugMode = false)
    {
        $this->output_dir = $output_dir . DIRECTORY_SEPARATOR . 'flickr';
        $this->cache_dir = $cache_dir . DIRECTORY_SEPARATOR . 'flickr';
        $this->flickr_key = $flickr_key;
        $this->debugMode = $debugMode;
        $this->ensureDirs();
    }

    protected function log($msg)
    {
        if (!$this->debugMode) {
            return;
        }
        error_log(__CLASS__ . '] ' . $msg);
    }

    protected function ensureDirs()
    {
        if (!is_dir($this->output_dir)) {
            $this->log("Making output dir: {$this->output_dir}");
            mkdir($this->output_dir, 0777, true);
        }
        if (!is_dir($this->cache_dir)) {
            $this->log("Making cache dir: {$this->cache_dir}");
            mkdir($this->cache_dir, 0777, true);
        }
    }

    protected function apiGet($method, $id = null, $params = [], $useCache = true)
    {
        $this->log("API call: {$method} {$id}");
        $cachePath = null;
        if ($id) {
            $params['photo_id'] = $id;
            if ($useCache) {
                $cachePath = $this->cache_dir . DIRECTORY_SEPARATOR . $method;
                if (!is_dir($cachePath)) {
                    mkdir($cachePath, 0777, true);
                }
                $cachePath .= DIRECTORY_SEPARATOR . $id . '.json';
                $this->log("Cache Path: {$cachePath}");
            }
        }

        if ($useCache && file_exists($cachePath)) {
            $url = $cachePath;
            $cachePath = null; // already used
        } else {
            $q = http_build_query(
                [
                    'method' => "flickr.{$method}",
                    'api_key' => $this->flickr_key,
                    'format' => 'json',
                    'nojsoncallback' => 1,
                ]
                + $params
            );
            $url = 'https://api.flickr.com/services/rest/?' . $q;
        }
        $this->log("Loading contents from {$url}");
        $contents = file_get_contents($url);
        if ($cachePath) {
            $this->log("Caching contents in {$cachePath}");
            file_put_contents($cachePath, $contents);
        }
        $result = json_decode($contents);
        // TODO: error handling
        return $result;
    }

    public function getFunctions()
    {
        return [
            new Twig_SimpleFunction('flickr_url', [$this, 'getUrl']),
            new Twig_SimpleFunction('flickr_img', [$this, 'getTemplatedImage'], ['is_safe' => ['html']]),
        ];
    }

    public function getName()
    {
        return 'flickr';
    }

    protected function makeOutputCopy($id, $url)
    {
        // TODO: don't assume jpeg
        $outputPath = $this->output_dir . DIRECTORY_SEPARATOR . $id . '.jpg';
        $cachePath = $this->cache_dir . DIRECTORY_SEPARATOR . $id . '.jpg';
        if (!file_exists($cachePath)) {
            $this->log("CACHE: Writing {$url}'s contents to {$cachePath}");
            file_put_contents($cachePath, file_get_contents($url));
        }
        if (!file_exists($outputPath)) {
            $this->log("OUTPUT: Writing {$cachePath}'s contents to {$outputPath}");
            copy($cachePath, $outputPath);
        }
        return "/flickr/{$id}.jpg";
    }

    protected function getSizeSource($id, $size)
    {
        $sizes = $this->apiGet('photos.getSizes', $id);
        // TODO: error handling
        foreach ($sizes->sizes->size as $imgSize) {
            if ($imgSize->label === $size) {
                return $this->makeOutputCopy($id, $imgSize->source);
            }
        }
        return false;
    }

    protected function getIdFromURL($url)
    {
        if (!preg_match('/\d+/', $url, $m)) {
            return false;
        }
        return $m[0];
    }

    public function getUrl($url, $size = 'large')
    {
        $id = $this->getIdFromURL($url);
        $size = ucfirst($size);
        return $this->getSizeSource($id, $size);
    }

    public function getTemplatedImage($url, $style = "wide")
    {
        $id = $this->getIdFromURL($url);
        $info = $this->apiGet('photos.getInfo', $id);

        $source = $this->getUrl($id);
        $title = $info->photo->title->_content;

        switch ($style) {
            case 'wide':
            default:
                $css = 'max-width:100%; height:auto;';
        }

        foreach ($info->photo->urls->url as $u) {
            if ('photopage' == $u->type) {
                $flickrUrl = $u->_content;
                break;
            }
        }
        return "<a href=\"{$flickrUrl}\"><img src=\"{$source}\" alt=\"{$title}\" title=\"{$title}\" style=\"{$css}\" /></a>";
    }
}
