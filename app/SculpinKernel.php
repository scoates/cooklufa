<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'FlickrTwigExtension.php';

class SculpinKernel extends \Sculpin\Bundle\SculpinBundle\HttpKernel\AbstractKernel
{
    protected function getAdditionalSculpinBundles()
    {
        return [];
    }
}
