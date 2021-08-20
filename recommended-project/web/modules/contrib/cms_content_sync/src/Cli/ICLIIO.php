<?php

namespace Drupal\cms_content_sync\Cli;

/**
 * Interface ICLIIO.
 */
interface ICLIIO
{
    /**
     * Ask the user to confirm interactively.
     *
     * @param string $text
     * @param bool   $default
     *
     * @return bool
     */
    public function confirm($text, $default = true);

    /**
     * @param string $text
     */
    public function success($text);

    /**
     * @param string $text
     */
    public function warning($text);

    /**
     * @param string $text
     */
    public function error($text);

    /**
     * @param string $text
     */
    public function text($text);
}
