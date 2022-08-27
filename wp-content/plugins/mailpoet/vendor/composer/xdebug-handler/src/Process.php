<?php
namespace Composer\XdebugHandler;
if (!defined('ABSPATH')) exit;
use Composer\Pcre\Preg;
class Process
{
 public static function escape($arg, $meta = true, $module = false)
 {
 if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
 return "'".str_replace("'", "'\\''", $arg)."'";
 }
 $quote = strpbrk($arg, " \t") !== false || $arg === '';
 $arg = Preg::replace('/(\\\\*)"/', '$1$1\\"', $arg, -1, $dquotes);
 if ($meta) {
 $meta = $dquotes || Preg::isMatch('/%[^%]+%/', $arg);
 if (!$meta) {
 $quote = $quote || strpbrk($arg, '^&|<>()') !== false;
 } elseif ($module && !$dquotes && $quote) {
 $meta = false;
 }
 }
 if ($quote) {
 $arg = '"'.(Preg::replace('/(\\\\*)$/', '$1$1', $arg)).'"';
 }
 if ($meta) {
 $arg = Preg::replace('/(["^&|<>()%])/', '^$1', $arg);
 }
 return $arg;
 }
 public static function escapeShellCommand(array $args)
 {
 $command = '';
 $module = array_shift($args);
 if ($module !== null) {
 $command = self::escape($module, true, true);
 foreach ($args as $arg) {
 $command .= ' '.self::escape($arg);
 }
 }
 return $command;
 }
 public static function setEnv($name, $value = null)
 {
 $unset = null === $value;
 if (!putenv($unset ? $name : $name.'='.$value)) {
 return false;
 }
 if ($unset) {
 unset($_SERVER[$name]);
 } else {
 $_SERVER[$name] = $value;
 }
 // Update $_ENV if it is being used
 if (false !== stripos((string) ini_get('variables_order'), 'E')) {
 if ($unset) {
 unset($_ENV[$name]);
 } else {
 $_ENV[$name] = $value;
 }
 }
 return true;
 }
}
