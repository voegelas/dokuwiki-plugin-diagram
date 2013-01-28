<?php
/**
 * DokuWiki plugin Diagram, Main component.
 *
 * Constructs diagrams.
 * See a full description at http://nikita.melnichenko.name/projects/dokuwiki-diagram/.
 *
 * Should work with any DokuWiki version >= 20070626.
 * Tested with DokuWiki versions 20090214, 20091225, 20110525a, 20121013.
 *
 * Install to lib/plugins/diagram/syntax/main.php.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Nikita Melnichenko [http://nikita.melnichenko.name]
 * @copyright Copyright 2007-2012, Nikita Melnichenko
 *
 * Thanks for help to:
 * - Anika Henke <anika[at]selfthinker.org>
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

	var $css_classes = array(
		/* spacers */
		'spacer-horizontal' => 'd-sh',
		'spacer-vertical' => 'd-sv',
		/* block */
		'block' => 'd-b',
		/* connection borders */
		'border-right-solid' => 'd-brs',
		'border-right-dashed' => 'd-brd',
		'border-bottom-solid' => 'd-bbs',
		'border-bottom-dashed' => 'd-bbd',
		/* arrow directions */
		'arrow-top' => 'd-at',
		'arrow-right' => 'd-ar',
		'arrow-bottom' => 'd-ab',
		'arrow-left' => 'd-al',
		'arrow-inside' => 'd-ai'
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
			'date'   => '2013-01-28',
			'name'   => 'Diagram plugin, Main component',
			'desc'   => 'Constructs diagrams',
			'url'    => 'http://nikita.melnichenko.name/projects/dokuwiki-diagram/index.php'
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
		// table cannot be put inside paragraphs
		return 'block';
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
	 * Supported abbreviation parameters:
	 * - border-color (CSS property)
	 * - background-color (CSS property)
	 * - text-align (CSS property)
	 * - padding (CSS property)
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
				$abbrs[$line_index][$data['abbr']]['params'] = array();
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
				$catching_abbr[] = array('cdata', array($data['text']), $call[2]);
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
				unset($commands[$i][$line_length - 1]);
		}

		return array($commands, $abbrs);
	}

	/**
	 * Generate table's framework.
	 *
	 * Framework: array(row number => array (column number => cell spec))
	 *   + array('n_rows' => number of rows, 'n_cols' => number of columns).
	 * cell_spec: array(
	 *   'colspan' => colspan (optional),
	 *   'rowspan' => rowspan (optional),
	 *   'classes' => array(css class),
	 *   'text' => text for diagram block or abbreviation (optional),
	 *   'content' => raw xhtml code to paste into cell, if 'text' key isn't set (optional)
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
		// store number of rows
		$res['n_rows'] = count($commands) * 2;
		// number of columns is computed below
		$res['n_cols'] = 0;

		for ($i = 0, $ir = 0; $i < count($commands); $i++, $ir += 2)
		{
			for ($j = 0, $jr = 0; $j < count($commands[$i]); $j++)
			{
				// leading and trailing spaces are already ignored by splitter component
				$cell_text = $commands[$i][$j];
				// split command to connection and arrow commands
				list($conn_command, $arrow_command) = $this->_splitCommand($cell_text);
				// 2x2 connection specs for current command
				$conn_cells = null;

				switch ($conn_command)
				{
					// === empty ===

					case "":
						$conn_cells = $this->_connectionCells('nnnn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('nssn', $arrow_command);
						break;
					case "F":
						$conn_cells = $this->_connectionCells('nddn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('nnss', $arrow_command);
						break;
					case "7":
						$conn_cells = $this->_connectionCells('nndd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('nsss', $arrow_command);
						break;
					case "V":
						$conn_cells = $this->_connectionCells('nddd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('snsn', $arrow_command);
						break;
					case ":":
						$conn_cells = $this->_connectionCells('dndn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('ssss', $arrow_command);
						break;
					case "%":
						$conn_cells = $this->_connectionCells('dddd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('nsns', $arrow_command);
						break;
					case "~":
						$conn_cells = $this->_connectionCells('ndnd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('ssnn', $arrow_command);
						break;
					case "L":
						$conn_cells = $this->_connectionCells('ddnn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('snns', $arrow_command);
						break;
					case "J":
						$conn_cells = $this->_connectionCells('dnnd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('ssns', $arrow_command);
						break;
					case "A":
						$conn_cells = $this->_connectionCells('ddnd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('snss', $arrow_command);
						break;
					case "C":
						$conn_cells = $this->_connectionCells('dndd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('sssn', $arrow_command);
						break;
					case "D":
						$conn_cells = $this->_connectionCells('dddn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('ndsd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('dsds', $arrow_command);
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
						$conn_cells = $this->_connectionCells('dsdn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('dnds', $arrow_command);
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
						$conn_cells = $this->_connectionCells('sdsn', $arrow_command);
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
						$conn_cells = $this->_connectionCells('snsd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('sdnd', $arrow_command);
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
						$conn_cells = $this->_connectionCells('sdsd', $arrow_command);
						break;

					// +     +     +
					//
					//
					//
					//
					//
					// +-----+-----+
					//       |
					//
					//       |
					//
					//       |
					// +     +     +
					case "p":
						$conn_cells = $this->_connectionCells('nsds', $arrow_command);
						break;

					// +     +     +
					//       |
					//
					//       |
					//
					//       |
					// +-----+-----+
					//
					//
					//
					//
					//
					// +     +     +
					case "b":
						$conn_cells = $this->_connectionCells('dsns', $arrow_command);
						break;

					// === box ===

					default:
						$res[$ir][$jr] = $this->_boxCell(6, 2, $cell_text);
						$jr += 6;
				}

				// apply connection cells to the result
				if (!is_null($conn_cells))
				{
					// we must have a proper order of creation of elements of framework, do not use list() here
					$res[$ir][$jr] = $conn_cells[0];
					$res[$ir][$jr + 1] = $conn_cells[1];
					$res[$ir + 1][$jr] = $conn_cells[2];
					$res[$ir + 1][$jr + 1] = $conn_cells[3];
					$jr += 2;
				}
			}

			// compute number of columns
			if ($res['n_cols'] < $jr)
				$res['n_cols'] = $jr;
		}

		return $res;
	}

	/**
	 * Split command to connection part and arrow part.
	 *
	 * @param string $command
	 * @return array array($connection_command, $arrow_command)
	 */
	function _splitCommand ($command)
	{
		$command_parts = explode('@', $command, 2);
		if (!isset($command_parts[1]) || !preg_match("/^[0-9a-f]{1,2}$/i", $command_parts[1]))
			$command_parts[1] = 0;
		else
		{
			// convert to bits: 'a' -> '0xa', 'ab' -> '0xba'
			// see docs and params of _connectionCells
			$v = $command_parts[1];
			if (strlen($v) == 2)
				$v = $v[1].$v[0];
			$command_parts[1] = intval($v, 16);
		}
		return $command_parts;
	}

	/**
	 * Generate box cell spec.
	 *
	 * Box is an entity with wiki text.
	 *
	 * @param integer $width colspan
	 * @param integer $height rowspan
	 * @param string $text box text or abbreviation
	 * @param string $border css border
	 * @param string $background_color css color
	 * @return array cell spec
	 */
	function _boxCell ($width, $height, $text)
	{
		return array(
			'colspan' => $width,
			'rowspan' => $height,
			'classes' => array($this->css_classes['block']),
			'text' => $text
			);
	}

	/**
	 * Generate 2x2 pattern of connections cells.
	 *
	 * Each connection cell provides connection lines using its borders.
	 * They could also contain divs with arrowheads.
	 *
	 * @param string $border_spec 4 chars containing line type in top, right, bottom, left directions;
	 *   line type chars are: 's' for solid, 'd' for dashed, 'n' for no line
	 * @param int $arrow_spec 8 bits are used:
	 *   the first 4 bits indicate if arrow exists (=1) or not (=0) in top, right, bottom, left directions,
	 *   the next 4 bits indicate if arrowhead look inside (=1) or outside (=0) in top, right, bottom, left directions,
	 * @return array array(cell_{0,0}, cell_{0,1}, cell_{1,0}, cell_{1,1})
	 */
	function _connectionCells ($border_spec, $arrow_spec)
	{
		// direction numbers: top (0), right (1), bottom (2), left (3)
		// cell numbers: {0,0} -> 0, {0,1} -> 1, {1,0} -> 2, {1,1} -> 3
		// +     +     +
		//       |
		//
		// cell  0  cell
		//  0        1
		//       |
		// +- 3 -+- 1 -+
		//       |
		//
		// cell  2  cell
		//  2        3
		//       |
		// +     +     +

		// init
		for ($i = 0; $i < 4; $i++)
			$cells[$i] = array('classes' => array());

		// fill borders
		if ($border_spec[0] != 'n')
			$cells[0]['classes'][] = $this->_borderClass($border_spec[0], 'right');
		if ($border_spec[1] != 'n')
			$cells[1]['classes'][] = $this->_borderClass($border_spec[1], 'bottom');
		if ($border_spec[2] != 'n')
			$cells[2]['classes'][] = $this->_borderClass($border_spec[2], 'right');
		if ($border_spec[3] != 'n')
			$cells[0]['classes'][] = $this->_borderClass($border_spec[3], 'bottom');

		// div elements with arrows, direction to cell number mapping
		// 0 -> 1, 1 -> 3. 2 -> 2, 3 -> 0
		// +     +     +
		//       |
		//
		//    0  0>>1
		//    ^
		//    ^  |
		// +- 3 -+- 1 -+
		//       |  v
		//          v
		//    2<<2  3
		//
		//       |
		// +     +     +

		// fill primary arrow classes
		if ($arrow_spec & (1 << 0))
		{
			$cells[1]['classes'][] = $this->css_classes['arrow-top'];
			$cells[1]['content'] = '<div />';
		}
		if ($arrow_spec & (1 << 1))
		{
			$cells[3]['classes'][] = $this->css_classes['arrow-right'];
			$cells[3]['content'] = '<div />';
		}
		if ($arrow_spec & (1 << 2))
		{
			$cells[2]['classes'][] = $this->css_classes['arrow-bottom'];
			$cells[2]['content'] = '<div />';
		}
		if ($arrow_spec & (1 << 3))
		{
			$cells[0]['classes'][] = $this->css_classes['arrow-left'];
			$cells[0]['content'] = '<div />';
		}
		// fill arrowhead direction
		if ($arrow_spec & (1 << (0 + 4)))
			$cells[1]['classes'][] = $this->css_classes['arrow-inside'];
		if ($arrow_spec & (1 << (1 + 4)))
			$cells[3]['classes'][] = $this->css_classes['arrow-inside'];
		if ($arrow_spec & (1 << (2 + 4)))
			$cells[2]['classes'][] = $this->css_classes['arrow-inside'];
		if ($arrow_spec & (1 << (3 + 4)))
			$cells[0]['classes'][] = $this->css_classes['arrow-inside'];

		// clear
		for ($i = 0; $i < 4; $i++)
			if (empty($cells[$i]['classes']))
				unset($cells[$i]['classes']);

		return $cells;
	}

	/**
	 * Generate border CSS class for connection cell.
	 *
	 * @param string $type 's' for solid, 'd' for dashed
	 * @param string $direction 'right'or 'bottom'
	 * @return string class name
	 */
	function _borderClass ($type, $direction)
	{
		if ($type != 's' && $type != 'd')
			return 'error';
		$key = "border-$direction-".($type == 's' ? 'solid' : 'dashed');
		return $this->css_classes[$key];
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
		$n_rows = $framework['n_rows'];
		$n_cols = $framework['n_cols'];

		// output table
		$table = '<table class="diagram">'."\n";
		// create horizontal spacer row
		// first cell is for column of vertical spacers
		$table .= "\t<tr>\n\t\t<td></td>\n";
		for ($i = 0; $i < $n_cols; $i++)
			$table .= "\t\t<td class=\"".$this->css_classes['spacer-horizontal']."\"><div /></td>\n";
		$table .= "\t</tr>\n";
		// create diagram rows
		for ($i = 0; $i < $n_rows; $i++)
		{
			// get table row spec
			$row = array_key_exists($i, $framework) ? $framework[$i] : array ();
			// line number
			$line_index = $i / 2;

			// output tr
			// first cell is for column of vertical spacers
			$table .= "\t<tr>\n\t\t<td class=\"".$this->css_classes['spacer-vertical']."\"><div /></td>\n";
			foreach ($row as $cell)
			{
				// generate cell content and update style
				$cell_content = '';
				// empty cell or connection cell
				if (!isset($cell['text']))
				{
					if (isset($cell['content']))
						$cell_content = $cell['content'];
				}
				// cell with abbreviation
				else if (array_key_exists($line_index, $abbrs) && array_key_exists($cell['text'], $abbrs[$line_index]))
				{
					$cell_content = $this->_renderWikiCalls ($abbrs[$line_index][$cell['text']]['content']);
					$cell['style'] = $this->_generateBlockStyle ($abbrs[$line_index][$cell['text']]['params']);
				}
				// cell with unrecognized abbreviation
				else
					$cell_content = $cell['text'];

				// output td
				$table .= "\t\t<td"
					.(isset($cell['classes']) && !empty($cell['classes']) ? ' class="'.implode(' ', $cell['classes']).'"' : '')
					.($cell['style'] != '' ? ' style="'.$cell["style"].'"' : '')
					.(isset($cell['colspan']) ? ' colspan="'.$cell["colspan"].'"' : '')
					.(isset($cell['rowspan']) ? ' rowspan="'.$cell["rowspan"].'"' : '')
					.'>'
					.$cell_content
					."</td>\n";
			}
			$table .= "\t</tr>\n";
		}
		$table .= "</table>\n";

		return $table;
	}

	/**
	 * Generate CSS style for diagram block.
	 *
	 * @param array $params supported block CSS parameters
	 * @return string css style
	 */
	function _generateBlockStyle ($params)
	{
		$css_props = array();
		foreach ($params as $param => $value)
			$css_props[] = "$param: $value;";
		return implode(' ', $css_props);
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
		if (preg_match("/^[a-z]+$/", $color))
			return true;
		// short number notation; for ex. '#e73'
		if (preg_match("/^#[0-9a-fA-F]{3}$/", $color))
			return true;
		// full number notation; for ex. '#ef703f'
		if (preg_match("/^#[0-9a-fA-F]{6}$/", $color))
			return true;
		// rgb notation; for ex. 'rgb(11,22,33)' or 'rgb(11%,22%,33%)'
		if (preg_match("/^rgb\([ ]*[0-9]{1,3}[ ]*,[ ]*[0-9]{1,3}[ ]*,[ ]*[0-9]{1,3}[ ]*\)$/", $color))
			return true;
		if (preg_match("/^rgb\([ ]*[0-9]{1,3}%[ ]*,[ ]*[0-9]{1,3}%[ ]*,[ ]*[0-9]{1,3}%[ ]*\)$/", $color))
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
		if (preg_match("/^((auto|[0-9]+px|[0-9]+%|[0-9]+em)[ ]*){1,4}$/", $value))
			return true;
		return false;
	}
}
