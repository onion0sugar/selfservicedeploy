<?php
/**
 * Self Service Deploy — klasa rejestrowana w GLPI (bez interfejsu).
 */
class PluginSelfservicedeploy extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Self Service Deploy';
    }
}
