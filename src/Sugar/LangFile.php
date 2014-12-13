<?php
/**
 * Manage a langfile to clean and sort.
 * It will remove unnesseary white spaces and
 * remove variables duplicates.
 */

namespace SugarCli\Sugar;

class LangFile
{
    const T_KEY = 0;
    const T_VALUE = 1;
    const T_LINE = 2;


    public $file;

    public $content;
    public $tokens;

    public $test_mode;
    public $logger;

    public $empty_blocks = array();
    public $end_blocks = array();
    public $var_blocks = array();

    /**
     * Construct a LangFile and parse the contents as php tokens.
     * @param file Filename of the file to load.
     * @param test_mode If true will try to replicate the original file without any changes.
     */
    public function __construct($file, $test_mode, $logger)
    {
        $this->file = $file;
        $this->test_mode = $test_mode;
        $this->logger = $logger;

        $this->content = file_get_contents($file);
        if ($this->content === false ) {
            throw new \Exception('Unable to load the file contents of ' . $file . '.');
        }
        $this->tokens = new \ArrayIterator(token_get_all($this->content));
    }

    /**
     * Manage simple tokens to return them as an array of token informations.
     */
    public static function normalizeToken($token)
    {
        if (! is_array($token)) {
            return array($token, $token, -1);
        }
        return $token;
    }

    /**
     * Return the token name as a string.
     */
    public static function getTokenName($token)
    {
        if (is_int($token[self::T_KEY])) {
            return token_name($token[self::T_KEY]);
        } else {
            return $token[self::T_KEY];
        }
    }

    /**
     * Read the variable definition block from token iterator.
     * It will stop at the next semicolon, php close tag or end of list.
     */
    public function parseNextBlock()
    {
        // Init block variables.
        $found_var = false;
        $found_equal = false;
        $found_semicolon = false;
        $found_close_tag = false;
        $end_block = false;
        $var_name = '';
        $var_value = '';
        $line = 0;

        // Loop through tokens until end of block or file.
        while(!$end_block && $this->tokens->valid()) {
            // Init loop variables
            $add_name = false;
            $process_var = true;

            // Get normed token array
            $token = static::normalizeToken($this->tokens->current());       
            $line = max($token[self::T_LINE], $line);
            $this->logger->debug('Found token ' . static::getTokenName($token) . " at line $line.");

            // Closing block allow only one whitespace.
            if($found_semicolon || $found_close_tag) {
                if ($token[self::T_KEY] == T_WHITESPACE) {
                    $end_block = true;
                    if (!$this->test_mode && $token[self::T_VALUE] != "\n") {
                        // Set only one line return after a semicolon or close tag.
                        $this->logger->notice("Removing spaces at line $line;");
                        $token[self::T_VALUE] = "\n";
                    }
                } else {
                    // Stop processing now.
                    break;
                }
            } else {
                switch($token[self::T_KEY]) {
                    // Comments and whitespace are allowed anywhere.
                    case T_COMMENT:
                    case T_DOC_COMMENT:
                    case T_WHITESPACE:
                        break;
                    // New variable
                    case T_VARIABLE:
                        if (!$found_var) {
                            // Set that we found a var definition and record the name
                            $found_var = true;
                            $add_name = true;
                        } else {
                            // We shouldn't see a variable definition.
                            throw new \Exception('Unexpected T_VARIABLE ' . $token[self::T_VALUE] . " at line $line.");
                        }
                        break;
                    // Variable name..
                    case '[':
                    case ']':
                    case T_CONSTANT_ENCAPSED_STRING:
                        if ($found_var) {
                            if (!$found_equal) {
                                // If inside variable definition, add name for sorting.
                                $add_name = true;
                            }
                        } else {
                            // Not in variable definition or value.
                            $name = static::getTokenName($token);
                            throw new \Exception('Unexpected ' . $name . " at line $line.");
                        }
                        break;
                    case '=':
                        // Equal allowed only after var definition
                        if ($found_var && !$found_equal) {
                            $found_equal = true;
                        } else {
                            throw new \Exception("Unexpected = at line $line.");
                        }
                        break;
                    case ';':
                        if ($found_var && !$found_equal) {
                            throw new \Exception("Unexpected ; at line $line.");
                        }
                        $found_semicolon = true;
                        break;
                    case T_CLOSE_TAG:
                        if ($found_var) {
                            throw new \Exception("Unexpected T_CLOSE_TAG at line $line.");
                        }
                        $found_close_tag = true;
                        break;
                    default:
                        if (!$found_var || !$found_equal) {
                            $name = static::getTokenName($token);
                            throw new \Exception('Unexpected ' . $name . " at line $line.");
                        }
                        break;
                }
            }
            if ($add_name) {
                $var_name .= $token[self::T_VALUE];
            }
            $var_value .= $token[self::T_VALUE];
            $this->logger->debug('Advance to next token.');
            $this->tokens->next();
        }
        if ($found_var && !$found_semicolon) {
            throw new \Exception('Missing ; before end of file.');
        }
        if (!$this->test_mode && "\n" != substr($var_value, -1)) {
            $this->logger->info('Add return line to end of block.');
            $var_value .= "\n";
        }
        if ($found_close_tag) {
            $this->end_blocks[] = $var_value;
        } elseif (!empty($var_name)) {
            if ($this->test_mode) {
                // Try to recreate the file as is.
                $this->var_blocks[] = $var_value;
            } else {
                $this->var_blocks[$var_name] = $var_value;
            }
        } else {
            $this->empty_blocks[] = $var_value;
        }
    }

    /**
     * Parse all tokens from the file and return them sorted and cleaned.
     * @param sort If true it will sort the variables.
     * @return A string with the original file data sorted.
     */
    public function getSortedFile($sort = true)
    {
        if (!$this->tokens->valid()) {
            $this->logger->info('File is empty.');
        }
        $token = static::normalizeToken($this->tokens->current());       
        if ($token[self::T_KEY] != T_OPEN_TAG) {
            throw new \Exception('Invalid file not starting with a PHP open tag.');
        }
        $this->logger->debug('Found php open tag.');
        $this->empty_blocks[] = $token[self::T_VALUE];
        $this->tokens->next();
        while($this->tokens->valid()) {
            $this->logger->info('parsing next block');
            $this->parseNextBlock();
        }
        if ($sort) {
            ksort($this->var_blocks);
        }
        $this->var_blocks = array_values($this->var_blocks);
        $blocks = array_merge($this->empty_blocks, $this->var_blocks, $this->end_blocks);
        return implode('', $blocks);
    }

}
