<?php
/**
 * DokuWiki plugin Diagram, Main component.
 *
 * Constructs diagrams.
 * See full description at http://nikita.melnichenko.name/projects/dokuwiki-diagram/.
 *
 * Tested with DokuWiki-20090214. Should also works with 20080505 and 20070626 releases.
 * Doesn't operate properly with 20061106 release due to its bugs.
 *
 * Install to lib/plugins/diagram/syntax/main.php.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Nikita Melnichenko [http://nikita.melnichenko.name]
 * @copyright Copyright 2007-2009, Nikita Melnichenko
 */

// includes
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * DokuWiki plugin Diagram, Main component.
 *
 * Constructs diagrams.
 */
class syntax_plugin_diagram_main extends DokuWiki_Syntax_Plugin
{
	/**
	 * Tag name in wiki text.
	 *
	 * @staticvar string
	 */
	var $tag_name = 'diagram';
	/**
	 * Splitter tag name in wiki text.
	 *
	 * Must be = syntax_plugin_diagram_splitter::$tag_name.
	 * Copied for compability with PHP4.
	 *
	 * @staticvar string
	 */
	var $tag_name_splitter = '_diagram_';
	
	/**
	 * Default parameters for abbreviation block.
	 *
	 * @staticvar string
	 */
	var $default_abbr_params = array(
		'border-width' => '2px',
		'border-style' => 'solid',
		'border-color' => 'black',
		'background-color' => null,
		'text-align' => 'center',
		'padding' => '0.25em',
		);

	/**
	 * Get general information.
	 *
	 * Return an associative array with the following values:
	 * - author - Author of the plugin
	 * - email  - Email address to contact the author
	 * - date   - Last modified date of the plugin in YYYY-MM-DD format
	 * - name   - Name of the plugin
	 * - desc   - Short description of the plugin (Text only)
	 * - url    - Website with more information on the plugin (eg. syntax description)
	 *
	 * @return array
	 */
	function getInfo ()
	{
		return array(
			'author' => 'Nikita Melnichenko',
			'date'   => '2009-08-11',
			'name'   => 'Diagram plugin, Main component',
			'desc'   => 'Constructs diagrams',
			'url'    => 'http://nikita.melnichenko.name/projects/dokuwiki-diagram/'
			);
	}

	/**
	 * Get syntax type.
	 *
	 * @return string one of the mode types defined in $PARSER_MODES in parser.php
	 */
	function getType ()
	{
		// containers are complex modes that can contain many other modes
		// plugin generates table, so type should be container
		return 'container';
	}

	/**
	 * Get paragraph type.
	 *
	 * Defines how this syntax is handled regarding paragraphs. This is important
	 * for correct XHTML nesting. Should return one of the following:
	 * - 'normal' - The plugin can be used inside paragraphs
	 * - 'block'  - Open paragraphs need to be closed before plugin output
	 * - 'stack'  - Special case. Plugin wraps other paragraphs.
	 *
	 * @return string
	 */
	function getPType ()
	{
		// table can be put inside paragraphs
		return 'normal';
	}

	/**
	 * Get position of plugin's mode in decision list.
	 *
	 * @return integer
	 */
	function getSort ()
	{
		// position doesn't matter
		return 999;
	}

	/**
	 * Connect pattern to lexer.
	 *
	 * @param string $mode
	 */
	function connectTo ($mode)
	{
		// parse all content in one shot
		$this->Lexer->addSpecialPattern('<'.$this->tag_name.'>.*?</'.$this->tag_name.'>',
			$mode, 'plugin_diagram_main');
	}

	/**
	 * Handle the match.
	 *
	 * @param string $match
	 * @param integer $state one of lexer states defined in lexer.php
	 * @param integer $pos position of first
	 * @param Doku_Handler $handler
	 * @return array data for rendering
	 */
	function handle ($match, $state, $pos, &$handler)
	{
		// strip tags
		$tag_name_len = strlen($this->tag_name);
		$content = substr($match, $tag_name_len + 2, strlen($match) - 2 * $tag_name_len - 5);

		// parse content using Splitter component
		$calls = p_get_instructions('<'.$this->tag_name_splitter.'>'
			.$content.'</'.$this->tag_name_splitter.'>');
		// compose commands and abbreviations
		list($commands, $abbrs) = $this->_genCommandsAndAbbrs($calls);

		// compose internal specification of table
		$framework = $this->_genFramework($commands);

		return array(
			'framework' => $framework,
			'abbreviations' => $abbrs
			);
	}

	/**
	 * Create XHTML text.
	 *
	 * @param string $mode render mode; only 'xhtml' supported
	 * @param Doku_Renderer $renderer
	 * @param array $data data from handler
	 * @return bool
	 */
	function render ($mode, &$renderer, $data)
	{
		if ($mode != 'xhtml')
			return false;

		// add generated code to document
		$renderer->doc .= "\n".$this->_renderDiagram(
			$data['framework'], $data['abbreviations']);
		return true;
	}

	/**
	 * Compose commands and abbreviations from wiki calls.
	 *
	 * @param array $calls DokuWiki calls
	 * @return array array($commands, $abbreviations)
	 */
	function _genCommandsAndAbbrs ($calls)
	{
		$diagram_entered = false;
		$commands = array();
		$abbrs = array();
		$line_index = -1;

		// handle call list
		foreach ($calls as $call)
		{
			// get plugin related info from call
			if ($call[0] == 'plugin' && $call[1][0] == 'diagram_splitter')
			{
				$data = $call[1][1];
				$diagram_call_state = $call[1][2];
			}
			else
			{
				$data = null;
				$diagram_call_state = 0;
			}

			// wait until entering to diagram
			if (!$diagram_entered	&& $diagram_call_state != DOKU_LEXER_ENTER)
				continue;
			// just entered: skipping
			if (!$diagram_entered)
			{
				$diagram_entered = true;
				continue;
			}

			// exited from diagram: stop handling
			if ($diagram_call_state == DOKU_LEXER_EXIT)
				break;

			// received newline: start new line
			if ($diagram_call_state == DOKU_LEXER_MATCHED && $data['type'] == 'newline')
			{
				// avoid unset lines of commands
				if ($line_index >= 0 && !array_key_exists($line_index, $commands))
					$commands[$line_index] = array ();
				// increment index
				$line_index++;
				// stop catching calls for abbr
				$abbr_met = false;
				if (isset($catching_abbr))
					unset($catching_abbr);
				continue;
			}
			// must receive first newline before processing commands
			if ($line_index < 0)
				continue;

			// received command: add to line of commands
			if ($diagram_call_state == DOKU_LEXER_MATCHED && $data['type'] == 'command')
			{
				// deny commands after first abbreviation in line
				if (!$abbr_met)
					$commands[$line_index][] = $data['command'];
				// stop catching calls for last abbr
				$abbr_met = false;
				if (isset($catching_abbr))
					unset($catching_abbr);
				continue;
			}

			// received abbreviation: add to line of abbrs and start catching calls
			if ($diagram_call_state == DOKU_LEXER_MATCHED && $data['type'] == 'abbr eval')
			{
				$abbr_met = true;
				$abbrs[$line_index][$data['abbr']]['content'] = array();
				// set defaults for each available parameter
				$abbrs[$line_index][$data['abbr']]['params'] = $this->default_abbr_params;
				// override some parameters by user values
				if (isset ($data['params']))
				{
					$params = explode(';', $data['params']);
					foreach ($params as $param)
					{
						list ($key, $value) = explode(':', $param);
						$key = trim ($key);
						$value = trim ($value);
						$is_valid = false;
						switch ($key)
						{
							case 'border-color':
							case 'background-color':
								$is_valid = $this->_validateCSSColor ($value);
								break;
							case 'text-align':
								$is_valid = $this->_validateCSSTextAlign ($value);
								break;
							case 'padding':
								$is_valid = $this->_validateCSSPadding ($value);
								break;
						}
						if ($is_valid)
							$abbrs[$line_index][$data['abbr']]['params'][$key] = $value;
					}
				}
				$catching_abbr = &$abbrs[$line_index][$data['abbr']]['content'];
				continue;
			}

			// received raw unmatched text and catching is on: add cdata call to abbr
			if ($diagram_call_state == DOKU_LEXER_UNMATCHED && isset($catching_abbr))
			{
				$catching_abbr[] = array('cdata', $data['text'], $call[2]);
				continue;
			}

			// received arbitrary call and catching is on: add call to abbr
			if (isset($catching_abbr))
				$catching_abbr[] = $call;

			// skip everything else
		}

		// remove trailing garbage
		for ($i = 0; $i < count($commands); $i++)
		{
			$line_length = count($commands[$i]);
			// remove last element, if no abbreviations found,
			// because last delimiter is a 'border' of the table
			// do not care, if someone specified garbage after last delimiter
			if ($line_length > 0 && !array_key_exists($i, $abbrs))
				unset($commands[$i][$line_length]);
		}

		return array($commands, $abbrs);
	}

	/**
	 * Generate table's framework.
	 *
	 * Framework: array(row number => array (column number => cell spec))
	 *   + array('line_count' => total line count).
	 * cell_spec: array(
	 *   "colspan" => colspan,
	 *   "rowspan" => rowspan,
	 *   "style" => style (or null),
	 *   "abbr" => abbr string (or null)
	 *   ).
	 *
	 * @author Nikita Melnichenko [http://nikita.melnichenko.name]
	 * @author Olesya Melnichenko [http://melnichenko.name]
	 *
	 * @param array $commands specification scheme
	 * @return array
	 */
	function _genFramework ($commands)
	{
		// internal settings
		// solid border for connection cell
		$border_cs = '1px solid black';
		// dashed border for connection cell
		$border_cd = '1px dashed black';

		// store line count that is used for proper table generation
		$res['line_count'] = count($commands);

		for ($i = 0, $ir = 0; $i < $res['line_count']; $i++, $ir += 2)
		{
			for ($j = 0, $jr = 0; $j < count($commands[$i]); $j++)
			{
				// leading and trailing spaces ignored (deprecated)
				$cell_text = trim($commands[$i][$j]);
				// flag for solid lines in connection cell
				$solid_lines = 0;

				switch ($cell_text)
				{
					// === empty ===

					case "":
						$res[$ir][$jr] = $this->_connectionCell(2, 2);
						$jr +=2;
						break;

					// === solid or dashed lines ===

					// +     +     +
					//
					//
					//
					//
					//
					// +     +-----+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case ",":
						$solid_lines = 1;
					case "F":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border, null);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr +=2;
						break;

					// +     +     +
					//
					//
					//
					//
					//
					// +-----+     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case ".":
						$solid_lines = 1;
					case "7":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1, null, $border);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 2);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border, null);

						$jr +=2;
						break;

					// +     +     +
					//
					//
					//
					//
					//
					// +-----+-----+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "v":
						$solid_lines = 1;
					case "V":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(2, 1, null, $border);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border, null);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "!":
						$solid_lines = 1;
					case ":":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 2, $border, null);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 2);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +-----+-----+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "+":
						$solid_lines = 1;
					case "%":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border, $border);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border, null);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// +     +     +
					//
					//
					//
					//
					//
					// +-----+-----+
					//
					//
					//
					//
					//
					// +     +     +
					case "-":
						$solid_lines = 1;
					case "~":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(2, 1, null, $border);
						$res[$ir + 1][$jr] = $this->_connectionCell(2, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +-----+
					//
					//
					//
					//
					//
					// +     +     +
					case "`":
						$solid_lines = 1;
					case "L":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border, null);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1,  null, $border);
						$res[$ir + 1][$jr] = $this->_connectionCell(2, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +-----+     +
					//
					//
					//
					//
					//
					// +     +     +
					case "'":
						$solid_lines = 1;
					case "J":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border, $border);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1);
						$res[$ir + 1][$jr] = $this->_connectionCell(2, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +-----+-----+
					//
					//
					//
					//
					//
					// +     +     +
					case "^":
						$solid_lines = 1;
					case "A":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border, $border);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border);
						$res[$ir + 1][$jr] = $this->_connectionCell(2, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +-----+     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "(":
						$solid_lines = 1;
					case "C":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border, $border);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 2);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border, null);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +-----+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case ")":
						$solid_lines = 1;
					case "D":
						$border = $solid_lines ? $border_cs : $border_cd;

						$res[$ir][$jr] = $this->_connectionCell(1, 2, $border, null);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// === mixed lines ===

					// +     +     +
					//
					//
					//
					//
					//
					// +- - -+- - -+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "y":
						$res[$ir][$jr] = $this->_connectionCell(2, 1, null, $border_cd);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border_cs, null);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//
					//       |
					//
					//       |
					// +-----+-----+
					//       |
					//
					//       |
					//
					//       |
					// +     +     +
					case "*":
						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border_cd, $border_cs);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border_cs);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border_cd, null);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//
					//       |
					//
					//       |
					// +     +-----+
					//       |
					//
					//       |
					//
					//       |
					// +     +     +
					case "}":
						$res[$ir][$jr] = $this->_connectionCell(1, 2, $border_cd, null);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border_cs);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//
					//       |
					//
					//       |
					// +-----+     +
					//       |
					//
					//       |
					//
					//       |
					// +     +     +
					case "{":
						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border_cd, $border_cs);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 2);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border_cd, null);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +- - -+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "]":
						$res[$ir][$jr] = $this->_connectionCell(1, 2, $border_cs, null);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border_cd);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +- - -+     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "[":
						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border_cs, $border_cd);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 2);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border_cs, null);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +- - -+- - -+
					//
					//
					//
					//
					//
					// +     +     +
					case "h":
						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border_cs, $border_cd);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border_cd);
						$res[$ir + 1][$jr] = $this->_connectionCell(2, 1);

						$jr += 2;
						break;

					// +     +     +
					//       |
					//       |
					//       |
					//       |
					//       |
					// +- - -+- - -+
					//       |
					//       |
					//       |
					//       |
					//       |
					// +     +     +
					case "#":
						$res[$ir][$jr] = $this->_connectionCell(1, 1, $border_cs, $border_cd);
						$res[$ir][$jr + 1] = $this->_connectionCell(1, 1, null, $border_cd);
						$res[$ir + 1][$jr] = $this->_connectionCell(1, 1, $border_cs, null);
						$res[$ir + 1][$jr + 1] = $this->_connectionCell(1, 1);

						$jr += 2;
						break;

					// === box ===

					default:
						$res[$ir][$jr] = $this->_boxCell(6, 2, $cell_text);

						$jr += 6;
				}
			}
		}

		return $res;
	}

	/**
	 * Generate box cell spec.
	 *
	 * Box is an entity with wiki text.
	 *
	 * @param integer $width colspan
	 * @param integer $height rowspan
	 * @param string $abbr abbreviation
	 * @param string $border css border
	 * @param string $background_color css color
	 * @return array cell spec
	 */
	function _boxCell ($width, $height, $abbr)
	{
		return array(
			"colspan" => $width,
			"rowspan" => $height,
			"abbr" => $abbr
			);
	}

	/**
	 * Generate connection cell.
	 *
	 * Connection cell provides connection lines using its borders.
	 *
	 * @param integer $width colspan
	 * @param integer $height rowspan
	 * @param string $border_right css border
	 * @param string $border_bottom css border
	 * @return array cell spec
	 */
	function _connectionCell ($width, $height, $border_right = null, $border_bottom = null)
	{
		$style = "";
		if (!is_null($border_right))
			$style .= "border-right: $border_right;";
		if (!is_null($border_bottom))
			$style .= ($style ? ' ' : '')."border-bottom: $border_bottom;";

		return array(
			"colspan" => $width,
			"rowspan" => $height,
			"style" => $style,
			"abbr" => null
			);
	}

	/**
	 * Generate table with diagram.
	 *
	 * @param array $framework table framework generated by _genFramework
	 * @param array $abbrs information about abbreviations
	 * @return string xhtml table
	 */
	function _renderDiagram ($framework, $abbrs)
	{
		// output table
		$table = '<table style="border-spacing: 0px; border: 0px;">'."\n";
		for ($i = 0; $i < 2 * $framework['line_count']; $i++)
		{
			// get table row spec
			$row = array_key_exists($i, $framework) ? $framework[$i] : array ();
			// line number
			$line_index = $i / 2;

			// output tr
			$table .= "\t<tr>\n";
			foreach ($row as $cell)
			{
				// generate cell content and update style

				// empty cell or connection cell
				if (is_null($cell['abbr']))
					$cell_content = '<div style="width: '
						.$cell["colspan"]
						.'em; height: '
						.$cell["rowspan"]
						.'em;"><span>&nbsp;</span></div>';
				// cell with abbreviation
				else if (array_key_exists($line_index, $abbrs) && array_key_exists($cell['abbr'], $abbrs[$line_index]))
				{
					$cell_content = $this->_renderWikiCalls ($abbrs[$line_index][$cell['abbr']]['content']);
					$cell["style"] = $this->_generateBlockStyle ($abbrs[$line_index][$cell['abbr']]['params']);
				}
				// cell with unrecognized abbreviation
				else
				{
					$cell_content = $cell['abbr'];
					$cell["style"] = $this->_generateBlockStyle ($this->default_abbr_params);
				}

				// output td
				$table .= "\t\t<td"
					.($cell["colspan"] != 1 ? ' colspan="'.$cell["colspan"].'"' : '')
					.($cell["rowspan"] != 1 ? ' rowspan="'.$cell["rowspan"].'"' : '')
					.($cell["style"] != "" ? ' style="'.$cell["style"].'"' : '')
					.">"
					.$cell_content
					."</td>\n";
			}
			$table .= "\t</tr>\n";
		}
		$table .= "</table>\n";

		return $table;
	}

	/**
	 * Generate CSS style for abbreviation block.
	 *
	 * @param array $params supported block parameters
	 * @return string css style
	 * @see default_abbr_params for list of supported block parameters
	 */
	function _generateBlockStyle ($params)
	{
		return "text-align: {$params['text-align']};"
			." padding: {$params['padding']};"
			." border: {$params['border-width']} {$params['border-style']} {$params['border-color']};"
			.(!is_null($params['background-color']) ? " background-color: {$params['background-color']};" : '');
	}

	/**
	 * Render wiki instructions.
	 *
	 * @param array $calls DokuWiki calls
	 * @return string xhtml markup
	 */
	function _renderWikiCalls ($calls)
	{
		return p_render('xhtml', $calls, $info);
	}

	/**
	 * Check if given color will not break css style.
	 *
	 * @param string $color checked string
	 * @return true, if string is good for css
	 */
	function _validateCSSColor ($color)
	{
		// color name; for ex. 'green'
		if (ereg("^[a-z]+$", $color))
			return true;
		// short number notation; for ex. '#e73'
		if (ereg("^#[0-9a-fA-F]{3}$", $color))
			return true;
		// full number notation; for ex. '#ef703f'
		if (ereg("^#[0-9a-fA-F]{6}$", $color))
			return true;
		// rgb notation; for ex. 'rgb(11,22,33)' or 'rgb(11%,22%,33%)'
		if (ereg("^rgb\([ ]*[0-9]{1,3}[ ]*,[ ]*[0-9]{1,3}[ ]*,[ ]*[0-9]{1,3}[ ]*\)$", $color))
			return true;
		if (ereg("^rgb\([ ]*[0-9]{1,3}%[ ]*,[ ]*[0-9]{1,3}%[ ]*,[ ]*[0-9]{1,3}%[ ]*\)$", $color))
			return true;
		return false;
	}

	/**
	 * Check if given value is proper for css text-align.
	 *
	 * @param string $value checked string
	 * @return true, if string is good as a value for css text-align
	 */
	function _validateCSSTextAlign ($value)
	{
		return $value == 'center' || $value == 'justify' || $value == 'left' || $value == 'right';
	}

	/**
	 * Check if given value is proper for css padding.
	 *
	 * @param string $value checked string
	 * @return true, if string is good as a value for css padding
	 */
	function _validateCSSPadding ($value)
	{
		if (ereg("^((auto|[0-9]+px|[0-9]+%|[0-9]+em)[ ]*){1,4}$", $value))
			return true;
		return false;
	}
}
