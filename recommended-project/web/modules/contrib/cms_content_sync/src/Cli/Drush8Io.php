<?php

namespace Drupal\cms_content_sync\Cli;

use Drush\Log\LogLevel;

/**
 * Class Drush8Io.
 *
 * This is a stand in for \Symfony\Component\Console\Style\StyleInterface with
 * drush 8 so that we don't need to depend on symfony components.
 */
class Drush8Io implements ICLIIO
{
    /**
     * {@inheritdoc}
     */
    public function confirm($text, $default = true)
    {
        return drush_confirm($text);
    }

    /**
     * {@inheritdoc}
     */
    public function success($text)
    {
        drush_log($text, LogLevel::SUCCESS);
    }

    /**
     * {@inheritdoc}
     */
    public function warning($text)
    {
        drush_log($text, LogLevel::WARNING);
    }

    /**
     * {@inheritdoc}
     */
    public function error($text)
    {
        drush_log($text, LogLevel::ERROR);
    }

    /**
     * {@inheritdoc}
     */
    public function text($text)
    {
        drush_print($text);
    }
}
