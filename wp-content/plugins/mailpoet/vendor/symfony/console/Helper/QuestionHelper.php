<?php
namespace Symfony\Component\Console\Helper;
if (!defined('ABSPATH')) exit;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Terminal;
class QuestionHelper extends Helper
{
 private $inputStream;
 private static $shell;
 private static $stty = true;
 private static $stdinIsInteractive;
 public function ask(InputInterface $input, OutputInterface $output, Question $question)
 {
 if ($output instanceof ConsoleOutputInterface) {
 $output = $output->getErrorOutput();
 }
 if (!$input->isInteractive()) {
 return $this->getDefaultAnswer($question);
 }
 if ($input instanceof StreamableInputInterface && $stream = $input->getStream()) {
 $this->inputStream = $stream;
 }
 try {
 if (!$question->getValidator()) {
 return $this->doAsk($output, $question);
 }
 $interviewer = function () use ($output, $question) {
 return $this->doAsk($output, $question);
 };
 return $this->validateAttempts($interviewer, $output, $question);
 } catch (MissingInputException $exception) {
 $input->setInteractive(false);
 if (null === $fallbackOutput = $this->getDefaultAnswer($question)) {
 throw $exception;
 }
 return $fallbackOutput;
 }
 }
 public function getName()
 {
 return 'question';
 }
 public static function disableStty()
 {
 self::$stty = false;
 }
 private function doAsk(OutputInterface $output, Question $question)
 {
 $this->writePrompt($output, $question);
 $inputStream = $this->inputStream ?: \STDIN;
 $autocomplete = $question->getAutocompleterCallback();
 if (null === $autocomplete || !self::$stty || !Terminal::hasSttyAvailable()) {
 $ret = false;
 if ($question->isHidden()) {
 try {
 $hiddenResponse = $this->getHiddenResponse($output, $inputStream, $question->isTrimmable());
 $ret = $question->isTrimmable() ? trim($hiddenResponse) : $hiddenResponse;
 } catch (RuntimeException $e) {
 if (!$question->isHiddenFallback()) {
 throw $e;
 }
 }
 }
 if (false === $ret) {
 $cp = $this->setIOCodepage();
 $ret = fgets($inputStream, 4096);
 $ret = $this->resetIOCodepage($cp, $ret);
 if (false === $ret) {
 throw new MissingInputException('Aborted.');
 }
 if ($question->isTrimmable()) {
 $ret = trim($ret);
 }
 }
 } else {
 $autocomplete = $this->autocomplete($output, $question, $inputStream, $autocomplete);
 $ret = $question->isTrimmable() ? trim($autocomplete) : $autocomplete;
 }
 if ($output instanceof ConsoleSectionOutput) {
 $output->addContent($ret);
 }
 $ret = \strlen($ret) > 0 ? $ret : $question->getDefault();
 if ($normalizer = $question->getNormalizer()) {
 return $normalizer($ret);
 }
 return $ret;
 }
 private function getDefaultAnswer(Question $question)
 {
 $default = $question->getDefault();
 if (null === $default) {
 return $default;
 }
 if ($validator = $question->getValidator()) {
 return \call_user_func($question->getValidator(), $default);
 } elseif ($question instanceof ChoiceQuestion) {
 $choices = $question->getChoices();
 if (!$question->isMultiselect()) {
 return $choices[$default] ?? $default;
 }
 $default = explode(',', $default);
 foreach ($default as $k => $v) {
 $v = $question->isTrimmable() ? trim($v) : $v;
 $default[$k] = $choices[$v] ?? $v;
 }
 }
 return $default;
 }
 protected function writePrompt(OutputInterface $output, Question $question)
 {
 $message = $question->getQuestion();
 if ($question instanceof ChoiceQuestion) {
 $output->writeln(array_merge([
 $question->getQuestion(),
 ], $this->formatChoiceQuestionChoices($question, 'info')));
 $message = $question->getPrompt();
 }
 $output->write($message);
 }
 protected function formatChoiceQuestionChoices(ChoiceQuestion $question, $tag)
 {
 $messages = [];
 $maxWidth = max(array_map([__CLASS__, 'strlen'], array_keys($choices = $question->getChoices())));
 foreach ($choices as $key => $value) {
 $padding = str_repeat(' ', $maxWidth - self::strlen($key));
 $messages[] = sprintf(" [<$tag>%s$padding</$tag>] %s", $key, $value);
 }
 return $messages;
 }
 protected function writeError(OutputInterface $output, \Exception $error)
 {
 if (null !== $this->getHelperSet() && $this->getHelperSet()->has('formatter')) {
 $message = $this->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error');
 } else {
 $message = '<error>'.$error->getMessage().'</error>';
 }
 $output->writeln($message);
 }
 private function autocomplete(OutputInterface $output, Question $question, $inputStream, callable $autocomplete): string
 {
 $fullChoice = '';
 $ret = '';
 $i = 0;
 $ofs = -1;
 $matches = $autocomplete($ret);
 $numMatches = \count($matches);
 $sttyMode = shell_exec('stty -g');
 // Disable icanon (so we can fread each keypress) and echo (we'll do echoing here instead)
 shell_exec('stty -icanon -echo');
 // Add highlighted text style
 $output->getFormatter()->setStyle('hl', new OutputFormatterStyle('black', 'white'));
 // Read a keypress
 while (!feof($inputStream)) {
 $c = fread($inputStream, 1);
 // as opposed to fgets(), fread() returns an empty string when the stream content is empty, not false.
 if (false === $c || ('' === $ret && '' === $c && null === $question->getDefault())) {
 shell_exec(sprintf('stty %s', $sttyMode));
 throw new MissingInputException('Aborted.');
 } elseif ("\177" === $c) { // Backspace Character
 if (0 === $numMatches && 0 !== $i) {
 --$i;
 $fullChoice = self::substr($fullChoice, 0, $i);
 // Move cursor backwards
 $output->write("\033[1D");
 }
 if (0 === $i) {
 $ofs = -1;
 $matches = $autocomplete($ret);
 $numMatches = \count($matches);
 } else {
 $numMatches = 0;
 }
 // Pop the last character off the end of our string
 $ret = self::substr($ret, 0, $i);
 } elseif ("\033" === $c) {
 // Did we read an escape sequence?
 $c .= fread($inputStream, 2);
 // A = Up Arrow. B = Down Arrow
 if (isset($c[2]) && ('A' === $c[2] || 'B' === $c[2])) {
 if ('A' === $c[2] && -1 === $ofs) {
 $ofs = 0;
 }
 if (0 === $numMatches) {
 continue;
 }
 $ofs += ('A' === $c[2]) ? -1 : 1;
 $ofs = ($numMatches + $ofs) % $numMatches;
 }
 } elseif (\ord($c) < 32) {
 if ("\t" === $c || "\n" === $c) {
 if ($numMatches > 0 && -1 !== $ofs) {
 $ret = (string) $matches[$ofs];
 // Echo out remaining chars for current match
 $remainingCharacters = substr($ret, \strlen(trim($this->mostRecentlyEnteredValue($fullChoice))));
 $output->write($remainingCharacters);
 $fullChoice .= $remainingCharacters;
 $i = (false === $encoding = mb_detect_encoding($fullChoice, null, true)) ? \strlen($fullChoice) : mb_strlen($fullChoice, $encoding);
 $matches = array_filter(
 $autocomplete($ret),
 function ($match) use ($ret) {
 return '' === $ret || str_starts_with($match, $ret);
 }
 );
 $numMatches = \count($matches);
 $ofs = -1;
 }
 if ("\n" === $c) {
 $output->write($c);
 break;
 }
 $numMatches = 0;
 }
 continue;
 } else {
 if ("\x80" <= $c) {
 $c .= fread($inputStream, ["\xC0" => 1, "\xD0" => 1, "\xE0" => 2, "\xF0" => 3][$c & "\xF0"]);
 }
 $output->write($c);
 $ret .= $c;
 $fullChoice .= $c;
 ++$i;
 $tempRet = $ret;
 if ($question instanceof ChoiceQuestion && $question->isMultiselect()) {
 $tempRet = $this->mostRecentlyEnteredValue($fullChoice);
 }
 $numMatches = 0;
 $ofs = 0;
 foreach ($autocomplete($ret) as $value) {
 // If typed characters match the beginning chunk of value (e.g. [AcmeDe]moBundle)
 if (str_starts_with($value, $tempRet)) {
 $matches[$numMatches++] = $value;
 }
 }
 }
 // Erase characters from cursor to end of line
 $output->write("\033[K");
 if ($numMatches > 0 && -1 !== $ofs) {
 // Save cursor position
 $output->write("\0337");
 // Write highlighted text, complete the partially entered response
 $charactersEntered = \strlen(trim($this->mostRecentlyEnteredValue($fullChoice)));
 $output->write('<hl>'.OutputFormatter::escapeTrailingBackslash(substr($matches[$ofs], $charactersEntered)).'</hl>');
 // Restore cursor position
 $output->write("\0338");
 }
 }
 // Reset stty so it behaves normally again
 shell_exec(sprintf('stty %s', $sttyMode));
 return $fullChoice;
 }
 private function mostRecentlyEnteredValue(string $entered): string
 {
 // Determine the most recent value that the user entered
 if (!str_contains($entered, ',')) {
 return $entered;
 }
 $choices = explode(',', $entered);
 if ('' !== $lastChoice = trim($choices[\count($choices) - 1])) {
 return $lastChoice;
 }
 return $entered;
 }
 private function getHiddenResponse(OutputInterface $output, $inputStream, bool $trimmable = true): string
 {
 if ('\\' === \DIRECTORY_SEPARATOR) {
 $exe = __DIR__.'/../Resources/bin/hiddeninput.exe';
 // handle code running from a phar
 if ('phar:' === substr(__FILE__, 0, 5)) {
 $tmpExe = sys_get_temp_dir().'/hiddeninput.exe';
 copy($exe, $tmpExe);
 $exe = $tmpExe;
 }
 $sExec = shell_exec('"'.$exe.'"');
 $value = $trimmable ? rtrim($sExec) : $sExec;
 $output->writeln('');
 if (isset($tmpExe)) {
 unlink($tmpExe);
 }
 return $value;
 }
 if (self::$stty && Terminal::hasSttyAvailable()) {
 $sttyMode = shell_exec('stty -g');
 shell_exec('stty -echo');
 } elseif ($this->isInteractiveInput($inputStream)) {
 throw new RuntimeException('Unable to hide the response.');
 }
 $value = fgets($inputStream, 4096);
 if (self::$stty && Terminal::hasSttyAvailable()) {
 shell_exec(sprintf('stty %s', $sttyMode));
 }
 if (false === $value) {
 throw new MissingInputException('Aborted.');
 }
 if ($trimmable) {
 $value = trim($value);
 }
 $output->writeln('');
 return $value;
 }
 private function validateAttempts(callable $interviewer, OutputInterface $output, Question $question)
 {
 $error = null;
 $attempts = $question->getMaxAttempts();
 while (null === $attempts || $attempts--) {
 if (null !== $error) {
 $this->writeError($output, $error);
 }
 try {
 return $question->getValidator()($interviewer());
 } catch (RuntimeException $e) {
 throw $e;
 } catch (\Exception $error) {
 }
 }
 throw $error;
 }
 private function isInteractiveInput($inputStream): bool
 {
 if ('php://stdin' !== (stream_get_meta_data($inputStream)['uri'] ?? null)) {
 return false;
 }
 if (null !== self::$stdinIsInteractive) {
 return self::$stdinIsInteractive;
 }
 if (\function_exists('stream_isatty')) {
 return self::$stdinIsInteractive = @stream_isatty(fopen('php://stdin', 'r'));
 }
 if (\function_exists('posix_isatty')) {
 return self::$stdinIsInteractive = @posix_isatty(fopen('php://stdin', 'r'));
 }
 if (!\function_exists('exec')) {
 return self::$stdinIsInteractive = true;
 }
 exec('stty 2> /dev/null', $output, $status);
 return self::$stdinIsInteractive = 1 !== $status;
 }
 private function setIOCodepage(): int
 {
 if (\function_exists('sapi_windows_cp_set')) {
 $cp = sapi_windows_cp_get();
 sapi_windows_cp_set(sapi_windows_cp_get('oem'));
 return $cp;
 }
 return 0;
 }
 private function resetIOCodepage(int $cp, $input)
 {
 if (0 !== $cp) {
 sapi_windows_cp_set($cp);
 if (false !== $input && '' !== $input) {
 $input = sapi_windows_cp_conv(sapi_windows_cp_get('oem'), $cp, $input);
 }
 }
 return $input;
 }
}
