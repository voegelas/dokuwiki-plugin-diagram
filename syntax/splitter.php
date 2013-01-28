<?php
/**
 * DokuWiki plugin Diagram, Splitter component.
 *
 * This is a helper for Main component.
 * The purpose is to describe, how diagram specification should be parsed in conjunction with other lexer modes.
 * Component also provides simple match handling.
 * Splitter can't be used without Main because we need to move wiki calls for abbreviations from their places to boxes.
 *
 * Should work with any DokuWiki version >= 20070626.
 * Tested with DokuWiki versions 20090214, 20091225, 20110525a, 20121013.
 *
 * Install to lib/plugins/diagram/syntax/splitter.php.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Nikita Melnichenko [http://nikita.melnichenko.name]
 * @copyright Copyright 2007-2012, Nikita Melnichenko
 */

// includes
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * DokuWiki plugin Diagram, Splitter component.
 *
 * Parses diagram content to get proper wiki calls for abbreviations.
 */
class syntax_plugin_diagram_splitter extends DokuWiki_Syntax_Plugin
{
	/**
	 * Tag name in wiki text.
	 *
	 * @staticvar string
	 */
	var $tag_name = '_diagram_';

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
			'name'   => 'Diagram plugin, Splitter component',
			'desc'   => 'Parses diagram content',
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
		// before Doku_Parser_Mode_table
		return 55;
	}

	/**
	 * Get allowed mode types.
	 *
	 * Defines the mode types for other dokuwiki markup that maybe nested within the
	 * plugin's own markup. Needs to return an array of one or more of the mode types
	 * defined in $PARSER_MODES in parser.php
	 *
	 * @return unknown
	 */
	function getAllowedTypes()
	{
		return array(
			'container',
			'substition',
			'protected',
			'disabled',
			'formatting'
			);
	}

	/**
	 * Connect pattern to lexer.
	 *
	 * @param string $mode
	 */
	function connectTo ($mode)
	{
		$this->Lexer->addEntryPattern('<'.$this->tag_name.'>',
			$mode, 'plugin_diagram_splitter');
	}

	/**
	 * Connect pattern to lexer (after connectTo).
	 */
	function postConnect ()
	{
		$this->Lexer->addPattern('\n',
			'plugin_diagram_splitter');
		$this->Lexer->addPattern('\|[A-Za-z0-9_]+=',
			'plugin_diagram_splitter');
		$this->Lexer->addPattern('\|[A-Za-z0-9_]+\{[^{}]*\}=',
			'plugin_diagram_splitter');
		$this->Lexer->addPattern('\|[^|=\n]*',
			'plugin_diagram_splitter');
		$this->Lexer->addExitPattern('</'.$this->tag_name.'>',
			'plugin_diagram_splitter');
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
		$res = array();
		if ($state == DOKU_LEXER_MATCHED)
		{
			if ($match == "\n")
				$res['type'] = 'newline';
			elseif ($match[strlen($match) - 1] == '=')
			{
				$res['type'] = 'abbr eval';
				// delete first '|', last '=' and whitespase
				$abbr_and_params = trim(substr($match, 1, -1));
				if (preg_match ('/([A-Za-z0-9_]+)\{([^{}]*)\}/', $abbr_and_params, $regs))
				{
					$res['abbr'] = $regs[1];
					$res['params'] = $regs[2];
				}
				else
					$res['abbr'] = $abbr_and_params;
			}
			else
			{
				$res['type'] = 'command';
				// delete first '|' and whitespase
				$res['command'] = trim(substr($match, 1));
			}
		}
		if ($state == DOKU_LEXER_UNMATCHED)
			$res['text'] = $match;
		return $res;
	}
}
