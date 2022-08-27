<?php
namespace Composer\Json;
if (!defined('ABSPATH')) exit;
use Composer\Pcre\Preg;
use Composer\Repository\PlatformRepository;
class JsonManipulator
{
 private static $DEFINES = '(?(DEFINE)
 (?<number> -? (?= [1-9]|0(?!\d) ) \d++ (\.\d++)? ([eE] [+-]?+ \d++)? )
 (?<boolean> true | false | null )
 (?<string> " ([^"\\\\]*+ | \\\\ ["\\\\bfnrt\/] | \\\\ u [0-9A-Fa-f]{4} )* " )
 (?<array> \[ (?: (?&json) \s*+ (?: , (?&json) \s*+ )*+ )?+ \s*+ \] )
 (?<pair> \s*+ (?&string) \s*+ : (?&json) \s*+ )
 (?<object> \{ (?: (?&pair) (?: , (?&pair) )*+ )?+ \s*+ \} )
 (?<json> \s*+ (?: (?&number) | (?&boolean) | (?&string) | (?&array) | (?&object) ) )
 )';
 private $contents;
 private $newline;
 private $indent;
 public function __construct($contents)
 {
 $contents = trim($contents);
 if ($contents === '') {
 $contents = '{}';
 }
 if (!Preg::isMatch('#^\{(.*)\}$#s', $contents)) {
 throw new \InvalidArgumentException('The json file must be an object ({})');
 }
 $this->newline = false !== strpos($contents, "\r\n") ? "\r\n" : "\n";
 $this->contents = $contents === '{}' ? '{' . $this->newline . '}' : $contents;
 $this->detectIndenting();
 }
 public function getContents()
 {
 return $this->contents . $this->newline;
 }
 public function addLink($type, $package, $constraint, $sortPackages = false)
 {
 $decoded = JsonFile::parseJson($this->contents);
 // no link of that type yet
 if (!isset($decoded[$type])) {
 return $this->addMainKey($type, array($package => $constraint));
 }
 $regex = '{'.self::$DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?)'.
 '(?P<property>'.preg_quote(JsonFile::encode($type)).'\s*:\s*)(?P<value>(?&json))(?P<end>.*)}sx';
 if (!Preg::isMatch($regex, $this->contents, $matches)) {
 return false;
 }
 $links = $matches['value'];
 // try to find existing link
 $packageRegex = str_replace('/', '\\\\?/', preg_quote($package));
 $regex = '{'.self::$DEFINES.'"(?P<package>'.$packageRegex.')"(\s*:\s*)(?&string)}ix';
 if (Preg::isMatch($regex, $links, $packageMatches)) {
 // update existing link
 $existingPackage = $packageMatches['package'];
 $packageRegex = str_replace('/', '\\\\?/', preg_quote($existingPackage));
 $links = Preg::replaceCallback('{'.self::$DEFINES.'"'.$packageRegex.'"(?P<separator>\s*:\s*)(?&string)}ix', function ($m) use ($existingPackage, $constraint) {
 return JsonFile::encode(str_replace('\\/', '/', $existingPackage)) . $m['separator'] . '"' . $constraint . '"';
 }, $links);
 } else {
 if (Preg::isMatch('#^\s*\{\s*\S+.*?(\s*\}\s*)$#s', $links, $match)) {
 // link missing but non empty links
 $links = Preg::replace(
 '{'.preg_quote($match[1]).'$}',
 // addcslashes is used to double up backslashes/$ since preg_replace resolves them as back references otherwise, see #1588
 addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $match[1], '\\$'),
 $links
 );
 } else {
 // links empty
 $links = '{' . $this->newline .
 $this->indent . $this->indent . JsonFile::encode($package).': '.JsonFile::encode($constraint) . $this->newline .
 $this->indent . '}';
 }
 }
 if (true === $sortPackages) {
 $requirements = json_decode($links, true);
 $this->sortPackages($requirements);
 $links = $this->format($requirements);
 }
 $this->contents = $matches['start'] . $matches['property'] . $links . $matches['end'];
 return true;
 }
 private function sortPackages(array &$packages = array())
 {
 $prefix = function ($requirement) {
 if (PlatformRepository::isPlatformPackage($requirement)) {
 return Preg::replace(
 array(
 '/^php/',
 '/^hhvm/',
 '/^ext/',
 '/^lib/',
 '/^\D/',
 ),
 array(
 '0-$0',
 '1-$0',
 '2-$0',
 '3-$0',
 '4-$0',
 ),
 $requirement
 );
 }
 return '5-'.$requirement;
 };
 uksort($packages, function ($a, $b) use ($prefix) {
 return strnatcmp($prefix($a), $prefix($b));
 });
 }
 public function addRepository($name, $config, $append = true)
 {
 return $this->addSubNode('repositories', $name, $config, $append);
 }
 public function removeRepository($name)
 {
 return $this->removeSubNode('repositories', $name);
 }
 public function addConfigSetting($name, $value)
 {
 return $this->addSubNode('config', $name, $value);
 }
 public function removeConfigSetting($name)
 {
 return $this->removeSubNode('config', $name);
 }
 public function addProperty($name, $value)
 {
 if (strpos($name, 'suggest.') === 0) {
 return $this->addSubNode('suggest', substr($name, 8), $value);
 }
 if (strpos($name, 'extra.') === 0) {
 return $this->addSubNode('extra', substr($name, 6), $value);
 }
 if (strpos($name, 'scripts.') === 0) {
 return $this->addSubNode('scripts', substr($name, 8), $value);
 }
 return $this->addMainKey($name, $value);
 }
 public function removeProperty($name)
 {
 if (strpos($name, 'suggest.') === 0) {
 return $this->removeSubNode('suggest', substr($name, 8));
 }
 if (strpos($name, 'extra.') === 0) {
 return $this->removeSubNode('extra', substr($name, 6));
 }
 if (strpos($name, 'scripts.') === 0) {
 return $this->removeSubNode('scripts', substr($name, 8));
 }
 return $this->removeMainKey($name);
 }
 public function addSubNode($mainNode, $name, $value, $append = true)
 {
 $decoded = JsonFile::parseJson($this->contents);
 $subName = null;
 if (in_array($mainNode, array('config', 'extra', 'scripts')) && false !== strpos($name, '.')) {
 list($name, $subName) = explode('.', $name, 2);
 }
 // no main node yet
 if (!isset($decoded[$mainNode])) {
 if ($subName !== null) {
 $this->addMainKey($mainNode, array($name => array($subName => $value)));
 } else {
 $this->addMainKey($mainNode, array($name => $value));
 }
 return true;
 }
 // main node content not match-able
 $nodeRegex = '{'.self::$DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
 preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&object))(?P<end>.*)}sx';
 try {
 if (!Preg::isMatch($nodeRegex, $this->contents, $match)) {
 return false;
 }
 } catch (\RuntimeException $e) {
 if ($e->getCode() === PREG_BACKTRACK_LIMIT_ERROR) {
 return false;
 }
 throw $e;
 }
 $children = $match['content'];
 // invalid match due to un-regexable content, abort
 if (!@json_decode($children)) {
 return false;
 }
 $that = $this;
 // child exists
 $childRegex = '{'.self::$DEFINES.'(?P<start>"'.preg_quote($name).'"\s*:\s*)(?P<content>(?&json))(?P<end>,?)}x';
 if (Preg::isMatch($childRegex, $children, $matches)) {
 $children = Preg::replaceCallback($childRegex, function ($matches) use ($subName, $value, $that) {
 if ($subName !== null) {
 $curVal = json_decode($matches['content'], true);
 if (!is_array($curVal)) {
 $curVal = array();
 }
 $curVal[$subName] = $value;
 $value = $curVal;
 }
 return $matches['start'] . $that->format($value, 1) . $matches['end'];
 }, $children);
 } else {
 Preg::match('#^{ (?P<leadingspace>\s*?) (?P<content>\S+.*?)? (?P<trailingspace>\s*) }$#sx', $children, $match);
 $whitespace = '';
 if (!empty($match['trailingspace'])) {
 $whitespace = $match['trailingspace'];
 }
 if (!empty($match['content'])) {
 if ($subName !== null) {
 $value = array($subName => $value);
 }
 // child missing but non empty children
 if ($append) {
 $children = Preg::replace(
 '#'.$whitespace.'}$#',
 addcslashes(',' . $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $whitespace . '}', '\\$'),
 $children
 );
 } else {
 $whitespace = '';
 if (!empty($match['leadingspace'])) {
 $whitespace = $match['leadingspace'];
 }
 $children = Preg::replace(
 '#^{'.$whitespace.'#',
 addcslashes('{' . $whitespace . JsonFile::encode($name).': '.$this->format($value, 1) . ',' . $this->newline . $this->indent . $this->indent, '\\$'),
 $children
 );
 }
 } else {
 if ($subName !== null) {
 $value = array($subName => $value);
 }
 // children present but empty
 $children = '{' . $this->newline . $this->indent . $this->indent . JsonFile::encode($name).': '.$this->format($value, 1) . $whitespace . '}';
 }
 }
 $this->contents = Preg::replaceCallback($nodeRegex, function ($m) use ($children) {
 return $m['start'] . $children . $m['end'];
 }, $this->contents);
 return true;
 }
 public function removeSubNode($mainNode, $name)
 {
 $decoded = JsonFile::parseJson($this->contents);
 // no node or empty node
 if (empty($decoded[$mainNode])) {
 return true;
 }
 // no node content match-able
 $nodeRegex = '{'.self::$DEFINES.'^(?P<start> \s* \{ \s* (?: (?&string) \s* : (?&json) \s* , \s* )*?'.
 preg_quote(JsonFile::encode($mainNode)).'\s*:\s*)(?P<content>(?&object))(?P<end>.*)}sx';
 try {
 if (!Preg::isMatch($nodeRegex, $this->contents, $match)) {
 return false;
 }
 } catch (\RuntimeException $e) {
 if ($e->getCode() === PREG_BACKTRACK_LIMIT_ERROR) {
 return false;
 }
 throw $e;
 }
 $children = $match['content'];
 // invalid match due to un-regexable content, abort
 if (!@json_decode($children, true)) {
 return false;
 }
 $subName = null;
 if (in_array($mainNode, array('config', 'extra', 'scripts')) && false !== strpos($name, '.')) {
 list($name, $subName) = explode('.', $name, 2);
 }
 // no node to remove
 if (!isset($decoded[$mainNode][$name]) || ($subName && !isset($decoded[$mainNode][$name][$subName]))) {
 return true;
 }
 // try and find a match for the subkey
 $keyRegex = str_replace('/', '\\\\?/', preg_quote($name));
 if (Preg::isMatch('{"'.$keyRegex.'"\s*:}i', $children)) {
 // find best match for the value of "name"
 if (Preg::isMatchAll('{'.self::$DEFINES.'"'.$keyRegex.'"\s*:\s*(?:(?&json))}x', $children, $matches)) {
 $bestMatch = '';
 foreach ($matches[0] as $match) {
 if (strlen($bestMatch) < strlen($match)) {
 $bestMatch = $match;
 }
 }
 $childrenClean = Preg::replace('{,\s*'.preg_quote($bestMatch).'}i', '', $children, -1, $count);
 if (1 !== $count) {
 $childrenClean = Preg::replace('{'.preg_quote($bestMatch).'\s*,?\s*}i', '', $childrenClean, -1, $count);
 if (1 !== $count) {
 return false;
 }
 }
 }
 } else {
 $childrenClean = $children;
 }
 if (!isset($childrenClean)) {
 throw new \InvalidArgumentException("JsonManipulator: \$childrenClean is not defined. Please report at https://github.com/composer/composer/issues/new.");
 }
 // no child data left, $name was the only key in
 Preg::match('#^{ \s*? (?P<content>\S+.*?)? (?P<trailingspace>\s*) }$#sx', $childrenClean, $match);
 if (empty($match['content'])) {
 $newline = $this->newline;
 $indent = $this->indent;
 $this->contents = Preg::replaceCallback($nodeRegex, function ($matches) use ($indent, $newline) {
 return $matches['start'] . '{' . $newline . $indent . '}' . $matches['end'];
 }, $this->contents);
 // we have a subname, so we restore the rest of $name
 if ($subName !== null) {
 $curVal = json_decode($children, true);
 unset($curVal[$name][$subName]);
 $this->addSubNode($mainNode, $name, $curVal[$name]);
 }
 return true;
 }
 $that = $this;
 $this->contents = Preg::replaceCallback($nodeRegex, function ($matches) use ($that, $name, $subName, $childrenClean) {
 if ($subName !== null) {
 $curVal = json_decode($matches['content'], true);
 unset($curVal[$name][$subName]);
 $childrenClean = $that->format($curVal);
 }
 return $matches['start'] . $childrenClean . $matches['end'];
 }, $this->contents);
 return true;
 }
 public function addMainKey($key, $content)
 {
 $decoded = JsonFile::parseJson($this->contents);
 $content = $this->format($content);
 // key exists already
 $regex = '{'.self::$DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?)'.
 '(?P<key>'.preg_quote(JsonFile::encode($key)).'\s*:\s*(?&json))(?P<end>.*)}sx';
 if (isset($decoded[$key]) && Preg::isMatch($regex, $this->contents, $matches)) {
 // invalid match due to un-regexable content, abort
 if (!@json_decode('{'.$matches['key'].'}')) {
 return false;
 }
 $this->contents = $matches['start'] . JsonFile::encode($key).': '.$content . $matches['end'];
 return true;
 }
 // append at the end of the file and keep whitespace
 if (Preg::isMatch('#[^{\s](\s*)\}$#', $this->contents, $match)) {
 $this->contents = Preg::replace(
 '#'.$match[1].'\}$#',
 addcslashes(',' . $this->newline . $this->indent . JsonFile::encode($key). ': '. $content . $this->newline . '}', '\\$'),
 $this->contents
 );
 return true;
 }
 // append at the end of the file
 $this->contents = Preg::replace(
 '#\}$#',
 addcslashes($this->indent . JsonFile::encode($key). ': '.$content . $this->newline . '}', '\\$'),
 $this->contents
 );
 return true;
 }
 public function removeMainKey($key)
 {
 $decoded = JsonFile::parseJson($this->contents);
 if (!array_key_exists($key, $decoded)) {
 return true;
 }
 // key exists already
 $regex = '{'.self::$DEFINES.'^(?P<start>\s*\{\s*(?:(?&string)\s*:\s*(?&json)\s*,\s*)*?)'.
 '(?P<removal>'.preg_quote(JsonFile::encode($key)).'\s*:\s*(?&json))\s*,?\s*(?P<end>.*)}sx';
 if (Preg::isMatch($regex, $this->contents, $matches)) {
 // invalid match due to un-regexable content, abort
 if (!@json_decode('{'.$matches['removal'].'}')) {
 return false;
 }
 // check that we are not leaving a dangling comma on the previous line if the last line was removed
 if (Preg::isMatch('#,\s*$#', $matches['start']) && Preg::isMatch('#^\}$#', $matches['end'])) {
 $matches['start'] = rtrim(Preg::replace('#,(\s*)$#', '$1', $matches['start']), $this->indent);
 }
 $this->contents = $matches['start'] . $matches['end'];
 if (Preg::isMatch('#^\{\s*\}\s*$#', $this->contents)) {
 $this->contents = "{\n}";
 }
 return true;
 }
 return false;
 }
 public function removeMainKeyIfEmpty($key)
 {
 $decoded = JsonFile::parseJson($this->contents);
 if (!array_key_exists($key, $decoded)) {
 return true;
 }
 if (is_array($decoded[$key]) && count($decoded[$key]) === 0) {
 return $this->removeMainKey($key);
 }
 return true;
 }
 public function format($data, $depth = 0)
 {
 if (is_array($data)) {
 reset($data);
 if (is_numeric(key($data))) {
 foreach ($data as $key => $val) {
 $data[$key] = $this->format($val, $depth + 1);
 }
 return '['.implode(', ', $data).']';
 }
 $out = '{' . $this->newline;
 $elems = array();
 foreach ($data as $key => $val) {
 $elems[] = str_repeat($this->indent, $depth + 2) . JsonFile::encode($key). ': '.$this->format($val, $depth + 1);
 }
 return $out . implode(','.$this->newline, $elems) . $this->newline . str_repeat($this->indent, $depth + 1) . '}';
 }
 return JsonFile::encode($data);
 }
 protected function detectIndenting()
 {
 if (Preg::isMatch('{^([ \t]+)"}m', $this->contents, $match)) {
 $this->indent = $match[1];
 } else {
 $this->indent = ' ';
 }
 }
}
