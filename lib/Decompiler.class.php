<?php

define('INDENT', "\t");
ini_set('error_reporting', E_ALL);
assert_options(ASSERT_ACTIVE, 0);

function color($str, $color = 33)
{
	return "\x1B[{$color}m$str\x1B[0m";
}

function printBacktrace() // {{{
{
	if (!$GLOBALS['__xcache_decompiler']->inComment++) {
		echo '/*', PHP_EOL;
	}
	$backtrace = debug_backtrace();
	foreach ($backtrace as $stack) {
		$args = array();
		foreach ($stack['args'] as $arg) {
			if (is_scalar($arg)) {
				$args[] = var_export($arg, true);
			}
			else if (is_array($arg)) {
				$array = array();
				foreach ($arg as $key => $value) {
					$array[] = var_export($key, true) . " => " . (is_scalar($value) ? var_export($value, true) : gettype($value));
					if (count($array) >= 5) {
						$array[] = '...';
						break;
					}
				}
				$args[] = "array(" . implode(', ', $array) . ')';
			}
			else {
				$args[] = gettype($arg);
			}
		}
		printf("%d: %s::%s(%s)" . PHP_EOL
				, $stack['line']
				, isset($stack['class']) ? $stack['class'] : ''
				, $stack['function']
				, implode(', ', $args)
				);
	}
	if (!--$GLOBALS['__xcache_decompiler']->inComment) {
		echo '*/', PHP_EOL;
	}
}
// }}}

function str($code, $indent = '') // {{{
{
	if (is_array($code)) {
		$array = array();
		foreach ($code as $key => $value) {
			$array[$key] = str($value, $indent);
		}
		return $array;
	}
	if (is_object($code)) {
		$code = foldToCode($code, $indent);
		return $code->toCode($indent);
	}

	return (string) $code;
}
// }}}
function unsetArray(&$array, $name) // {{{
{
	unset($array[$name]);
}
// }}}

function foldToCode($src, $indent = '') // {{{ wrap or rewrap anything to Decompiler_Code
{
	if (is_array($indent)) {
		$indent = $indent['indent'];
	}

	if (!is_object($src)) {
		return new Decompiler_Code($src);
	}

	if (!method_exists($src, 'toCode')) {
		var_dump($src);
		exit('no toCode');
	}
	if (get_class($src) != 'Decompiler_Code') {
		// rewrap it
		$src = new Decompiler_Code($src->toCode($indent));
	}

	return $src;
}
// }}}
function decompileAst($ast, $EX) // {{{
{
	$kind = $ast['kind'];
	$children = $ast['children'];
	unset($ast['kind']);
	unset($ast['children']);
	switch ($kind) {
	case ZEND_CONST:
		return value($ast[0], $EX);

	case XC_INIT_ARRAY:
		$array = new Decompiler_Array();
		for ($i = 0; $i < $children; $i += 2) {
			if (isset($ast[$i + 1])) {
				$key = decompileAst($ast[$i], $EX);
				$value = decompileAst($ast[$i + 1], $EX);
				$array->value[] = array($key, $value, '');
			}
			else {
				$array->value[] = array(null, decompileAst($ast[$i], $EX), '');
			}
		}
		return $array;

	// ZEND_BOOL_AND: handled in binop
	// ZEND_BOOL_OR:  handled in binop

	case ZEND_SELECT:
		return new Decompiler_TernaryOp(
				decompileAst($ast[0], $EX)
				, decompileAst($ast[1], $EX)
				, decompileAst($ast[2], $EX)
				);

	case ZEND_UNARY_PLUS:
		return new Decompiler_Code('+' . str(decompileAst($ast[0], $EX)));

	case ZEND_UNARY_MINUS:
		return new Decompiler_Code('-' . str(decompileAst($ast[0], $EX)));

	default:
		$decompiler = $GLOBALS['__xcache_decompiler'];
		if (isset($decompiler->binaryOp[$kind])) {
			return new Decompiler_BinaryOp($decompiler
					, decompileAst($ast[0], $EX)
					, $kind
					, decompileAst($ast[1], $EX)
					);
		}

		return "un-handled kind $kind in zend_ast";
	}
}
// }}}
function value($value, &$EX) // {{{
{
	if (ZEND_ENGINE_2_6 && (xcache_get_type($value) & IS_CONSTANT_TYPE_MASK) == IS_CONSTANT_AST) {
		return decompileAst(xcache_dasm_ast($value), $EX);
	}

	$originalValue = xcache_get_special_value($value);
	if (isset($originalValue)) {
		if ((xcache_get_type($value) & IS_CONSTANT_TYPE_MASK) == IS_CONSTANT) {
			// constant
			return $GLOBALS['__xcache_decompiler']->stripNamespace($originalValue);
		}

		$value = $originalValue;
	}

	if (is_a($value, 'Decompiler_Object')) {
		// use as is
	}
	else if (is_array($value)) {
		$value = new Decompiler_ConstArray($value, $EX);
	}
	else {
		if (isset($EX['value2constant'][$value])) {
			$value = new Decompiler_Code($EX['value2constant'][$value]);
		}
		else if (isset($GLOBALS['__xcache_decompiler']->value2constant[$value])) {
			$value = new Decompiler_Code($GLOBALS['__xcache_decompiler']->value2constant[$value]);
		}
		else {
			$value = new Decompiler_Value($value);
		}
	}
	return $value;
}
// }}}
function unquoteName_($str, $asVariableName, $indent = '') // {{{
{
	$str = str($str, $indent);
	if (preg_match("!^'[\\w_][\\w\\d_\\\\]*'\$!", $str)) {
		return str_replace('\\\\', '\\', substr($str, 1, -1));
	}
	else if ($asVariableName) {
		return "{" . $str . "}";
	}
	else {
		return $str;
	}
}
// }}}
function unquoteVariableName($str, $indent = '') // {{{
{
	return unquoteName_($str, true, $indent);
}
// }}}
function unquoteName($str, $indent = '') // {{{
{
	return unquoteName_($str, false, $indent);
}
// }}}
class Decompiler_Object // {{{
{
}
// }}}
class Decompiler_Value extends Decompiler_Object // {{{
{
	var $value;

	function Decompiler_Value($value = null)
	{
		$this->value = $value;
	}

	function toCode($indent)
	{
		$code = var_export($this->value, true);
		if (gettype($this->value) == 'string') {
			$code = preg_replace_callback("![\t\r\n]+!", array(&$this, 'convertNewline'), $code);
			$code = preg_replace_callback("![\\x01-\\x1f\\x7f-\\xff]+!", array(&$this, 'escape8BitString'), $code);
			$code = preg_replace("!^'' \\. \"|\" \\. ''\$!", '"', $code);
		}
		return $code;
	}

	function convertNewline($m)
	{
		return "' . \"" . strtr($m[0], array("\t" => "\\t", "\r" => "\\r", "\n" => "\\n")) . "\" . '";
	}

	function escape8BitString($m)
	{
		// TODO: recognize $encoding
		$s = $m[0];
		$escaped = '';
		for ($i = 0, $c = strlen($s); $i < $c; ++$i) {
			$escaped .= "\\x" . dechex(ord($s[$i]));
		}
		return "' . \"" . $escaped . "\" . '";
	}
}
// }}}
class Decompiler_Code extends Decompiler_Object // {{{
{
	var $src;

	function Decompiler_Code($src)
	{
		if (!assert('isset($src)')) {
			printBacktrace();
		}
		$this->src = $src;
	}

	function toCode($indent)
	{
		return $this->src;
	}
}
// }}}
class Decompiler_UnaryOp extends Decompiler_Code // {{{
{
	var $parent;
	var $opc;
	var $op;

	function Decompiler_UnaryOp($parent, $opc, $op)
	{
		$this->parent = &$parent;
		$this->opc = $opc;
		$this->op = $op;
	}

	function toCode($indent)
	{
		$opstr = $this->parent->unaryOp[$this->opc];

		if (is_a($this->op, 'Decompiler_TernaryOp') || is_a($this->op, 'Decompiler_BinaryOp') && $this->op->opc != $this->opc) {
			$op = "(" . str($this->op, $indent) . ")";
		}
		else {
			$op = $this->op;
		}
		return $opstr . str($op, $indent);
	}
}
// }}}
class Decompiler_BinaryOp extends Decompiler_Code // {{{
{
	var $opc;
	var $op1;
	var $op2;
	var $parent;

	function Decompiler_BinaryOp($parent, $op1, $opc, $op2)
	{
		$this->parent = &$parent;
		$this->opc = $opc;
		$this->op1 = $op1;
		$this->op2 = $op2;
	}

	function toCode($indent)
	{
		$opstr = $this->parent->binaryOp[$this->opc];

		if (is_a($this->op1, 'Decompiler_TernaryOp') || is_a($this->op1, 'Decompiler_BinaryOp') && $this->op1->opc != $this->opc) {
			$op1 = "(" . str($this->op1, $indent) . ")";
		}
		else {
			$op1 = $this->op1;
		}

		if (is_a($this->op2, 'Decompiler_TernaryOp') || is_a($this->op2, 'Decompiler_BinaryOp') && $this->op2->opc != $this->opc && substr($opstr, -1) != '=') {
			$op2 = "(" . str($this->op2, $indent) . ")";
		}
		else {
			$op2 = $this->op2;
		}

		if (str($op1) == '0' && ($this->opc == XC_ADD || $this->opc == XC_SUB)) {
			return $opstr . str($op2, $indent);
		}

		return str($op1, $indent) . ' ' . $opstr . ($this->opc == XC_ASSIGN_REF ? '' : ' ') . str($op2, $indent);
	}
}
// }}}
class Decompiler_TernaryOp extends Decompiler_Code // {{{
{
	var $condition;
	var $trueValue;
	var $falseValue;

	function Decompiler_TernaryOp($condition, $trueValue, $falseValue)
	{
		$this->condition = $condition;
		$this->trueValue = $trueValue;
		$this->falseValue = $falseValue;
	}

	function toCode($indent)
	{
		$trueValue = $this->trueValue;
		if (is_a($this->trueValue, 'Decompiler_TernaryOp')) {
			$trueValue = "(" . str($trueValue, $indent) . ")";
		}
		$falseValue = $this->falseValue;
		if (is_a($this->falseValue, 'Decompiler_TernaryOp')) {
			$falseValue = "(" . str($falseValue, $indent) . ")";
		}

		return str($this->condition) . ' ? ' . str($trueValue) . ' : ' . str($falseValue);
	}
}
// }}}
class Decompiler_Fetch extends Decompiler_Code // {{{
{
	var $src;
	var $fetchType;

	function Decompiler_Fetch($src, $type, $globalSrc)
	{
		$this->src = $src;
		$this->fetchType = $type;
		$this->globalSrc = $globalSrc;
	}

	function toCode($indent)
	{
		switch ($this->fetchType) {
		case ZEND_FETCH_LOCAL:
			return '$' . $this->src;
		case ZEND_FETCH_STATIC:
			if (ZEND_ENGINE_2_3) {
				// closure local variable?
				return 'STR' . str($this->src);
			}
			else {
				$EX = array();
				return str(value($this->src, $EX));
			}
			die('static fetch cant to string');
		case ZEND_FETCH_GLOBAL:
		case ZEND_FETCH_GLOBAL_LOCK:
			return $this->globalSrc;
		default:
			var_dump($this->fetchType);
			assert(0);
		}
	}
}
// }}}
class Decompiler_Box // {{{
{
	var $obj;

	function Decompiler_Box(&$obj)
	{
		$this->obj = &$obj;
	}

	function toCode($indent)
	{
		return $this->obj->toCode($indent);
	}
}
// }}}
class Decompiler_Dim extends Decompiler_Value // {{{
{
	var $offsets = array();
	var $isLast = false;
	var $isObject = false;
	var $assign = null;

	function toCode($indent)
	{
		if (is_a($this->value, 'Decompiler_ListBox')) {
			$exp = str($this->value->obj->src, $indent);
		}
		else {
			$exp = str($this->value, $indent);
		}
		$last = count($this->offsets) - 1;
		foreach ($this->offsets as $i => $dim) {
			if ($this->isObject && $i == $last) {
				$exp .= '->' . unquoteVariableName($dim, $indent);
			}
			else {
				$exp .= '[' . str($dim, $indent) . ']';
			}
		}
		return $exp;
	}
}
// }}}
class Decompiler_DimBox extends Decompiler_Box // {{{
{
}
// }}}
class Decompiler_List extends Decompiler_Code // {{{
{
	var $src;
	var $dims = array();
	var $everLocked = false;

	function toCode($indent)
	{
		if (count($this->dims) == 1 && !$this->everLocked) {
			$dim = $this->dims[0];
			unset($dim->value);
			$dim->value = $this->src;
			if (!isset($dim->assign)) {
				return str($dim, $indent);
			}
			return str($this->dims[0]->assign, $indent) . ' = ' . str($dim, $indent);
		}
		/* flatten dims */
		$assigns = array();
		foreach ($this->dims as $dim) {
			$assign = &$assigns;
			foreach ($dim->offsets as $offset) {
				$assign = &$assign[$offset];
			}
			$assign = foldToCode($dim->assign, $indent);
		}
		return str($this->toList($assigns)) . ' = ' . str($this->src, $indent);
	}

	function toList($assigns)
	{
		$keys = array_keys($assigns);
		if (count($keys) < 2) {
			$keys[] = 0;
		}
		$max = call_user_func_array('max', $keys);
		$list = "list(";
		for ($i = 0; $i <= $max; $i++) {
			if ($i) {
				$list .= ', ';
			}
			if (!isset($assigns[$i])) {
				continue;
			}
			if (is_array($assigns[$i])) {
				$list .= $this->toList($assigns[$i]);
			}
			else {
				$list .= $assigns[$i];
			}
		}
		return $list . ')';
	}
}
// }}}
class Decompiler_ListBox extends Decompiler_Box // {{{
{
}
// }}}
class Decompiler_Array extends Decompiler_Value // {{{
{
	// elements
	function Decompiler_Array()
	{
		$this->value = array();
	}

	function toCode($indent)
	{
		$subindent = $indent . INDENT;

		$elementsCode = array();
		$index = 0;
		foreach ($this->value as $element) {
			list($key, $value, $ref) = $element;
			if (!isset($key)) {
				$key = $index++;
			}
			$elementsCode[] = array(str($key, $subindent), str($value, $subindent), $key, $value, $ref);
		}

		$exp = "array(";
		$indent = $indent . INDENT;
		$assocWidth = 0;
		$multiline = 0;
		$i = 0;
		foreach ($elementsCode as $element) {
			list($keyCode, $valueCode) = $element;
			if ((string) $i !== $keyCode) {
				$assocWidth = 1;
				break;
			}
			++$i;
		}
		foreach ($elementsCode as $element) {
			list($keyCode, $valueCode, $key, $value) = $element;
			if ($assocWidth) {
				$len = strlen($keyCode);
				if ($assocWidth < $len) {
					$assocWidth = $len;
				}
			}
			if (is_array($value) || is_a($value, 'Decompiler_Array')) {
				$multiline++;
			}
		}

		$i = 0;
		foreach ($elementsCode as $element) {
			list($keyCode, $value, , , $ref) = $element;
			if ($multiline) {
				if ($i) {
					$exp .= ",";
				}
				$exp .= PHP_EOL;
				$exp .= $indent;
			}
			else {
				if ($i) {
					$exp .= ", ";
				}
			}

			if ($assocWidth) {
				if ($multiline) {
					$exp .= sprintf("%-{$assocWidth}s => ", $keyCode);
				}
				else {
					$exp .= $keyCode . ' => ';
				}
			}

			$exp .= $ref;
			$exp .= $value;

			$i++;
		}
		if ($multiline) {
			$exp .= PHP_EOL . "$indent)";
		}
		else {
			$exp .= ")";
		}
		return $exp;
	}
}
// }}}
class Decompiler_ConstArray extends Decompiler_Array // {{{
{
	function Decompiler_ConstArray($array, &$EX)
	{
		$elements = array();
		foreach ($array as $key => $value) {
			if ((xcache_get_type($value) & IS_CONSTANT_INDEX)) {
				$keyCode = $GLOBALS['__xcache_decompiler']->stripNamespace(
						ZEND_ENGINE_2_3
						? substr($key, 0, -2)
						: $key
						);
			}
			else {
				$keyCode = value($key, $EX);
			}
			$elements[] = array($keyCode, value($value, $EX), '');
		}
		$this->value = $elements;
	}
}
// }}}
class Decompiler_ForeachBox extends Decompiler_Box // {{{
{
	var $iskey;

	function toCode($indent)
	{
		return '#foreachBox#';
	}
}
// }}}

class Decompiler
{
	var $namespace;
	var $namespaceDecided;
	var $activeFile;
	var $activeDir;
	var $activeClass;
	var $activeMethod;
	var $activeFunction;
	var $outputPhp;
	var $outputOpcode;
	var $inComment = 0;
	var $value2constant = array();

	function Decompiler($outputTypes)
	{
		$this->outputPhp = in_array('php', $outputTypes);
		$this->outputOpcode = in_array('opcode', $outputTypes);
		$GLOBALS['__xcache_decompiler'] = $this;
		// {{{ testing
		// XC_UNDEF XC_OP_DATA
		$this->test = !empty($_ENV['XCACHE_DECOMPILER_TEST']);
		$this->usedOps = array();

		if ($this->test) {
			$content = file_get_contents(__FILE__);
			for ($i = 0; $opname = xcache_get_opcode($i); $i++) {
				if (!preg_match("/\\bXC_" . $opname . "\\b(?!')/", $content)) {
					echo "not recognized opcode ", $opname, PHP_EOL;
				}
			}
		}
		// }}}
		// {{{ opinfo
		$this->unaryOp = array(
				XC_BW_NOT   => '~',
				XC_BOOL_NOT => '!',
				);
		$this->binaryOp = array(
				XC_ADD                 => "+",
				XC_ASSIGN_ADD          => "+=",
				XC_SUB                 => "-",
				XC_ASSIGN_SUB          => "-=",
				XC_MUL                 => "*",
				XC_ASSIGN_MUL          => "*=",
				XC_DIV                 => "/",
				XC_ASSIGN_DIV          => "/=",
				XC_MOD                 => "%",
				XC_ASSIGN_MOD          => "%=",
				XC_SL                  => "<<",
				XC_ASSIGN_SL           => "<<=",
				XC_SR                  => ">>",
				XC_ASSIGN_SR           => ">>=",
				XC_CONCAT              => ".",
				XC_ASSIGN_CONCAT       => ".=",
				XC_POW                 => "**",
				XC_ASSIGN_POW          => "*=",
				XC_IS_IDENTICAL        => "===",
				XC_IS_NOT_IDENTICAL    => "!==",
				XC_IS_EQUAL            => "==",
				XC_IS_NOT_EQUAL        => "!=",
				XC_IS_SMALLER          => "<",
				XC_IS_SMALLER_OR_EQUAL => "<=",
				XC_BW_OR               => "|",
				XC_ASSIGN_BW_OR        => "|=",
				XC_BW_AND              => "&",
				XC_ASSIGN_BW_AND       => "&=",
				XC_BW_XOR              => "^",
				XC_ASSIGN_BW_XOR       => "^=",
				XC_BOOL_XOR            => "xor",
				XC_ASSIGN              => "=",
				XC_ASSIGN_REF          => "= &",
				XC_JMP_SET             => "?:",
				XC_JMP_SET_VAR         => "?:",
				XC_JMPZ_EX             => "&&",
				XC_JMPNZ_EX            => "||",
				);
		if (defined('IS_CONSTANT_AST')) {
			$this->binaryOp[ZEND_BOOL_AND] = '&&';
			$this->binaryOp[ZEND_BOOL_OR]  = '||';
		}
		// }}}
		$this->includeTypes = array( // {{{
				ZEND_EVAL         => 'eval',
				ZEND_INCLUDE      => 'include',
				ZEND_INCLUDE_ONCE => 'include_once',
				ZEND_REQUIRE      => 'require',
				ZEND_REQUIRE_ONCE => 'require_once',
				);
				// }}}
	}
	function detectNamespace($name) // {{{
	{
		if ($this->namespaceDecided) {
			return;
		}

		if (strpos($name, '\\') !== false) {
			$namespace = strtok($name, '\\');
			if ($namespace == $this->namespace) {
				return;
			}

			$this->namespace = $namespace;
			echo 'namespace ', $this->namespace, ";", PHP_EOL, PHP_EOL;
		}

		$this->namespaceDecided = true;
	}
	// }}}
	function stripNamespace($name) // {{{
	{
		if (!isset($name)) {
			return $name;
		}

		$name = str($name);
		$len = strlen($this->namespace) + 1;
		if (strncasecmp($name, $this->namespace . '\\', $len) == 0) {
			return substr($name, $len);
		}
		else {
			return $name;
		}
	}
	// }}}
	function outputPhp($range) // {{{
	{
		$EX = &$range['EX'];
		$needBlankline = isset($EX['lastBlock']);
		$indent = $EX['indent'];
		$curticks = 0;
		for ($i = $range[0]; $i <= $range[1]; $i++) {
			$op = $EX['opcodes'][$i];
			if (isset($op['gofrom'])) {
				if ($needBlankline) {
					$needBlankline = false;
					echo PHP_EOL;
				}
				echo 'label' . $i, ":", PHP_EOL;
			}
			if (isset($op['php'])) {
				$toticks = isset($op['ticks']) ? (int) str($op['ticks']) : 0;
				if ($curticks != $toticks) {
					$oldticks = $curticks;
					$curticks = $toticks;
					if (!$curticks) {
						echo $EX['indent'], "}", PHP_EOL, PHP_EOL;
						$indent = $EX['indent'];
					}
					else {
						if ($oldticks) {
							echo $EX['indent'], "}", PHP_EOL, PHP_EOL;
						}
						else if (!$oldticks) {
							$indent .= INDENT;
						}
						if ($needBlankline) {
							$needBlankline = false;
							echo PHP_EOL;
						}
						echo $EX['indent'], "declare (ticks=$curticks) {", PHP_EOL;
					}
				}
				if ($needBlankline) {
					$needBlankline = false;
					echo PHP_EOL;
				}
				echo $indent, str($op['php'], $indent), ";", PHP_EOL;
				$EX['lastBlock'] = 'basic';
			}
		}
		if ($curticks) {
			echo $EX['indent'], "}", PHP_EOL;
		}
	}
	// }}}
	function getOpVal($op, &$EX, $free = false) // {{{
	{
		switch ($op['op_type']) {
		case XC_IS_CONST:
			return value($op['constant'], $EX);

		case XC_IS_VAR:
		case XC_IS_TMP_VAR:
			$T = &$EX['Ts'];
			if (!isset($T[$op['var']])) {
				if ($this->outputPhp && isset($free)) {
					printBacktrace();
				}
				return null;
			}
			$ret = $T[$op['var']];
			if ($free && empty($this->keepTs)) {
				unset($T[$op['var']]);
			}
			return $ret;

		case XC_IS_CV:
			$var = $op['var'];
			$var = $EX['op_array']['vars'][$var];
			return '$' . $var['name'];

		case XC_IS_UNUSED:
			return null;
		}
	}
	// }}}
	function removeKeyPrefix($array, $prefix) // {{{
	{
		$prefixLen = strlen($prefix);
		$ret = array();
		foreach ($array as $key => $value) {
			if (substr($key, 0, $prefixLen) == $prefix) {
				$key = substr($key, $prefixLen);
			}
			$ret[$key] = $value;
		}
		return $ret;
	}
	// }}}
	function fixOpCode($opcodes, $removeTailing = false, $defaultReturnValue = null) // {{{
	{
		$last = count($opcodes) - 1;
		for ($i = 0; $i <= $last; $i++) {
			if (function_exists('xcache_get_fixed_opcode')) {
				$opcodes[$i]['opcode'] = xcache_get_fixed_opcode($opcodes[$i]['opcode'], $i);
			}
			if (isset($opcodes[$i]['op1'])) {
				$opcodes[$i]['op1'] = $this->removeKeyPrefix($opcodes[$i]['op1'], 'u.');
				$opcodes[$i]['op2'] = $this->removeKeyPrefix($opcodes[$i]['op2'], 'u.');
				$opcodes[$i]['result'] = $this->removeKeyPrefix($opcodes[$i]['result'], 'u.');
			}
			else {
				$op = array(
					'op1' => array(),
					'op2' => array(),
					'result' => array(),
				);
				foreach ($opcodes[$i] as $name => $value) {
					if (preg_match('!^(op1|op2|result)\\.(.*)!', $name, $m)) {
						list(, $which, $field) = $m;
						$op[$which][$field] = $value;
					}
					else if (preg_match('!^(op1|op2|result)_type$!', $name, $m)) {
						list(, $which) = $m;
						$op[$which]['op_type'] = $value;
					}
					else {
						$op[$name] = $value;
					}
				}
				$opcodes[$i] = $op;
			}
		}

		if ($removeTailing) {
			$last = count($opcodes) - 1;
			if ($opcodes[$last]['opcode'] == XC_HANDLE_EXCEPTION) {
				$this->usedOps[XC_HANDLE_EXCEPTION] = true;
				$opcodes[$last]['opcode'] = XC_NOP;
				--$last;
			}
			if ($opcodes[$last]['opcode'] == XC_RETURN
			 || $opcodes[$last]['opcode'] == XC_GENERATOR_RETURN) {
				$op1 = $opcodes[$last]['op1'];
				if ($op1['op_type'] == XC_IS_CONST && array_key_exists('constant', $op1) && $op1['constant'] === $defaultReturnValue) {
					$opcodes[$last]['opcode'] = XC_NOP;
					--$last;
				}
			}
		}
		return $opcodes;
	}
	// }}}
	function decompileBasicBlock($range, $unhandled = false) // {{{
	{
		$this->dasmBasicBlock($range);
		if ($unhandled) {
			$this->dumpRange($range);
		}
		$this->outputPhp($range);
	}
	// }}}
	function isIfCondition($range) // {{{
	{
		$opcodes = &$range['EX']['opcodes'];
		$firstOp = &$opcodes[$range[0]];
		return $firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmptos']) && $opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_JMP
		 && !empty($opcodes[$firstOp['jmptos'][0] - 1]['jmptos'])
		 && $opcodes[$firstOp['jmptos'][0] - 1]['jmptos'][0] == $range[1] + 1;
	}
	// }}}
	function removeJmpInfo(&$EX, $line) // {{{
	{
		$opcodes = &$EX['opcodes'];
		if (!isset($opcodes[$line]['jmptos'])) {
			printBacktrace();
		}
		foreach ($opcodes[$line]['jmptos'] as $jmpTo) {
			$jmpfroms = &$opcodes[$jmpTo]['jmpfroms'];
			$jmpfroms = array_flip($jmpfroms);
			unset($jmpfroms[$line]);
			$jmpfroms = array_keys($jmpfroms);
		}
		// $opcodes[$line]['opcode'] = XC_NOP;
		unset($opcodes[$line]['jmptos']);
	}
	// }}}
	function beginScope(&$EX, $doIndent = true) // {{{
	{
		array_push($EX['scopeStack'], array($EX['lastBlock'], $EX['indent']));
		if ($doIndent) {
			$EX['indent'] .= INDENT;
		}
		$EX['lastBlock'] = null;
	}
	// }}}
	function endScope(&$EX) // {{{
	{
		list($EX['lastBlock'], $EX['indent']) = array_pop($EX['scopeStack']);
	}
	// }}}
	function beginComplexBlock(&$EX) // {{{
	{
		if (isset($EX['lastBlock'])) {
			echo PHP_EOL;
			$EX['lastBlock'] = null;
		}
	}
	// }}}
	function endComplexBlock(&$EX) // {{{
	{
		$EX['lastBlock'] = 'complex';
	}
	// }}}
	function op($range, $offset) // {{{
	{
		$opcodes = &$range['EX']['opcodes'];
		if ($offset > 0) {
			for ($i = $offset; $i <= $range[1]; ++$i) {
				if ($opcodes[$i]['opcode'] != XC_NOP) {
					return $i;
				}
			}
		}
		else {
			for ($i = -$offset; $i >= $range[0]; --$i) {
				if ($opcodes[$i]['opcode'] != XC_NOP) {
					return $i;
				}
			}
		}
		return -1;
	}
	// }}}
	function decompileComplexBlock($range) // {{{
	{
		$EX = &$range['EX'];
		$opcodes = &$EX['opcodes'];

		$firstOp = &$opcodes[$this->op($range, $range[0])];
		$lastOp = &$opcodes[$this->op($range, -$range[1])];

		// {{{ && || and or
		if (($firstOp['opcode'] == XC_JMPZ_EX || $firstOp['opcode'] == XC_JMPNZ_EX) && !empty($firstOp['jmptos'])
		 && $firstOp['jmptos'][0] == $range[1] + 1
		 && $lastOp['opcode'] == XC_BOOL
		 && $firstOp['opcode']['result']['var'] == $lastOp['opcode']['result']['var']
		) {
			$this->removeJmpInfo($EX, $range[0]);

			$this->recognizeAndDecompileClosedBlocks(array($range[0], $range[0], 'EX' => &$EX));
			$op1 = $this->getOpVal($firstOp['result'], $EX, true);

			$this->recognizeAndDecompileClosedBlocks(array($range[0] + 1, $range[1], 'EX' => &$EX));
			$op2 = $this->getOpVal($lastOp['result'], $EX, true);

			$EX['Ts'][$firstOp['result']['var']] = new Decompiler_BinaryOp($this, $op1, $firstOp['opcode'], $op2);
			return false;
		}
		// }}}
		// {{{ ?: excluding JMP_SET/JMP_SET_VAR
		if ($firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmptos'])
		 && $range[1] >= $range[0] + 3
		 && ($opcodes[$firstOp['jmptos'][0] - 2]['opcode'] == XC_QM_ASSIGN || $opcodes[$firstOp['jmptos'][0] - 2]['opcode'] == XC_QM_ASSIGN_VAR)
		 && $opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_JMP && $opcodes[$firstOp['jmptos'][0] - 1]['jmptos'][0] == $range[1] + 1
		 && ($lastOp['opcode'] == XC_QM_ASSIGN || $lastOp['opcode'] == XC_QM_ASSIGN_VAR)
		) {
			$trueRange = array($range[0] + 1, $firstOp['jmptos'][0] - 2, 'EX' => &$EX);
			$falseRange = array($firstOp['jmptos'][0], $range[1], 'EX' => &$EX);
			$this->removeJmpInfo($EX, $range[0]);

			$condition = $this->getOpVal($firstOp['op1'], $EX);
			$this->recognizeAndDecompileClosedBlocks($trueRange);
			$trueValue = $this->getOpVal($opcodes[$trueRange[1]]['result'], $EX, true);
			$this->recognizeAndDecompileClosedBlocks($falseRange);
			$falseValue = $this->getOpVal($opcodes[$falseRange[1]]['result'], $EX, true);
			$EX['Ts'][$opcodes[$trueRange[1]]['result']['var']] = new Decompiler_TernaryOp($condition, $trueValue, $falseValue);
			return false;
		}
		// }}}
		// {{{ goto (TODO: recognize BRK which is translated to JMP by optimizer)
		if ($firstOp['opcode'] == XC_JMP && !empty($firstOp['jmptos']) && $firstOp['jmptos'][0] == $range[1] + 1) {
			$this->removeJmpInfo($EX, $range[0]);
			assert(XC_GOTO != -1);
			$firstOp['opcode'] = XC_GOTO;
			$target = $firstOp['op1']['var'];
			$firstOp['goto'] = $target;
			$opcodes[$target]['gofrom'][] = $range[0];

			$this->recognizeAndDecompileClosedBlocks($range);
			return false;
		}
		// }}}

		// {{{ search firstJmpOp
		$firstJmpOp = null;
		for ($i = $range[0]; $i <= $range[1]; ++$i) {
			if (!empty($opcodes[$i]['jmptos'])) {
				$firstJmpOp = &$opcodes[$i];
				break;
			}
		}
		// }}}
		if (!isset($firstJmpOp)) {
			return;
		}
		// {{{ search lastJmpOp
		$lastJmpOp = null;
		for ($i = $range[1]; $i > $firstJmpOp['line']; --$i) {
			if (!empty($opcodes[$i]['jmptos'])) {
				$lastJmpOp = &$opcodes[$i];
				break;
			}
		}
		// }}}
		if ($this->decompile_foreach($range, $EX, $opcodes, $firstOp, $lastOp, $firstJmpOp, $lastJmpOp)) {
			return true;
		}
		if ($this->decompile_while($range, $EX, $opcodes, $firstOp, $lastOp, $firstJmpOp)) {
			return true;
		}
		if ($this->decompile_for($range, $EX, $opcodes, $firstOp, $lastOp)) {
			return true;
		}
		if ($this->decompile_if($range, $EX, $opcodes, $firstOp, $lastOp)) {
			return true;
		}
		if ($this->decompile_switch($range, $EX, $opcodes, $firstOp, $lastOp)) {
			return true;
		}
		if ($this->decompile_tryCatch($range, $EX, $opcodes, $firstOp, $lastOp)) {
			return true;
		}
		if ($this->decompile_doWhile($range, $EX, $opcodes, $firstOp, $lastOp)) {
			return true;
		}

		$this->decompileBasicBlock($range, true);
	}
	// }}}
	function decompile_for($range, &$EX, &$opcodes, &$firstOp, &$lastOp) // {{{
	{
		if (!empty($firstOp['jmpfroms']) && $opcodes[$firstOp['jmpfroms'][0]]['opcode'] == XC_JMP
		 && $lastOp['opcode'] == XC_JMP && !empty($lastOp['jmptos']) && $lastOp['jmptos'][0] <= $firstOp['jmpfroms'][0]
		 && !empty($opcodes[$range[1] + 1]['jmpfroms']) && $opcodes[$opcodes[$range[1] + 1]['jmpfroms'][0]]['opcode'] == XC_JMPZNZ
		) {
			$nextRange = array($lastOp['jmptos'][0], $firstOp['jmpfroms'][0], 'EX' => &$EX);
			$conditionRange = array($range[0], $nextRange[0] - 1, 'EX' => &$EX);
			$this->removeJmpInfo($EX, $conditionRange[1]);
			$bodyRange = array($nextRange[1], $range[1], 'EX' => &$EX);
			$this->removeJmpInfo($EX, $bodyRange[1]);

			$initial = '';
			$this->beginScope($EX);
			$this->dasmBasicBlock($conditionRange);
			$conditionCodes = array();
			for ($i = $conditionRange[0]; $i <= $conditionRange[1]; ++$i) {
				if (isset($opcodes[$i]['php'])) {
					$conditionCodes[] = str($opcodes[$i]['php'], $EX);
				}
			}
			$conditionCodes[] = str($this->getOpVal($opcodes[$conditionRange[1]]['op1'], $EX), $EX);
			if (implode(',', $conditionCodes) == 'true') {
				$conditionCodes = array();
			}
			$this->endScope($EX);

			$this->beginScope($EX);
			$this->dasmBasicBlock($nextRange);
			$nextCodes = array();
			for ($i = $nextRange[0]; $i <= $nextRange[1]; ++$i) {
				if (isset($opcodes[$i]['php'])) {
					$nextCodes[] = str($opcodes[$i]['php'], $EX);
				}
			}
			$this->endScope($EX);

			$this->beginComplexBlock($EX);
			echo $EX['indent'], 'for (', str($initial, $EX), '; ', implode(', ', $conditionCodes), '; ', implode(', ', $nextCodes), ') ', '{', PHP_EOL;
			$this->clearJmpInfo_brk_cont($bodyRange);
			$this->beginScope($EX);
			$this->recognizeAndDecompileClosedBlocks($bodyRange);
			$this->endScope($EX);
			echo $EX['indent'], '}', PHP_EOL;
			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function decompile_if($range, &$EX, &$opcodes, &$firstOp, &$lastOp) // {{{
	{
		if ($this->isIfCondition($range)) {
			$this->beginComplexBlock($EX);
			$isElseIf = false;
			do {
				$ifRange = array($range[0], $opcodes[$range[0]]['jmptos'][0] - 1, 'EX' => &$EX);
				$this->removeJmpInfo($EX, $ifRange[0]);
				$this->removeJmpInfo($EX, $ifRange[1]);
				$condition = $this->getOpVal($opcodes[$ifRange[0]]['op1'], $EX);

				echo $EX['indent'], $isElseIf ? 'else if' : 'if', ' (', str($condition, $EX), ') ', '{', PHP_EOL;
				$this->beginScope($EX);
				$this->recognizeAndDecompileClosedBlocks($ifRange);
				$this->endScope($EX);
				$EX['lastBlock'] = null;
				echo $EX['indent'], '}', PHP_EOL;

				$isElseIf = true;
				// search for else if
				$range[0] = $ifRange[1] + 1;
				for ($i = $ifRange[1] + 1; $i <= $range[1]; ++$i) {
					// find first jmpout
					if (!empty($opcodes[$i]['jmptos'])) {
						if ($this->isIfCondition(array($i, $range[1], 'EX' => &$EX))) {
							$this->dasmBasicBlock(array($range[0], $i, 'EX' => &$EX));
							$range[0] = $i;
						}
						break;
					}
				}
			} while ($this->isIfCondition($range));
			if ($ifRange[1] <= $range[1]) {
				$elseRange = array($ifRange[1], $range[1], 'EX' => &$EX);
				echo $EX['indent'], 'else ', '{', PHP_EOL;
				$this->beginScope($EX);
				$this->recognizeAndDecompileClosedBlocks($elseRange);
				$this->endScope($EX);
				$EX['lastBlock'] = null;
				echo $EX['indent'], '}', PHP_EOL;
			}
			$this->endComplexBlock($EX);
			return true;
		}

		if ($firstOp['opcode'] == XC_JMPZ && !empty($firstOp['jmptos'])
		 && $firstOp['jmptos'][0] - 1 == $range[1]
		 && ($opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_RETURN || $opcodes[$firstOp['jmptos'][0] - 1]['opcode'] == XC_GENERATOR_RETURN)) {
			$this->beginComplexBlock($EX);
			$this->removeJmpInfo($EX, $range[0]);
			$condition = $this->getOpVal($opcodes[$range[0]]['op1'], $EX);

			echo $EX['indent'], 'if (', str($condition, $EX), ') ', '{', PHP_EOL;
			$this->beginScope($EX);
			$this->recognizeAndDecompileClosedBlocks($range);
			$this->endScope($EX);
			echo $EX['indent'], '}', PHP_EOL;
			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function decompile_tryCatch($range, &$EX, &$opcodes, &$firstOp, &$lastOp) // {{{
	{
		if (!empty($firstOp['jmpfroms']) && !empty($opcodes[$firstOp['jmpfroms'][0]]['isCatchBegin'])) {
			$catchBlocks = array();
			$catchFirst = $firstOp['jmpfroms'][0];

			$tryRange = array($range[0], $catchFirst - 1, 'EX' => &$EX);

			// search for XC_CATCH
			for ($i = $catchFirst; $i <= $range[1]; ) {
				if ($opcodes[$i]['opcode'] == XC_CATCH) {
					$catchOpLine = $i;
					$this->removeJmpInfo($EX, $catchFirst);

					$catchNext = $opcodes[$catchOpLine]['extended_value'];
					$catchBodyLast = $catchNext - 1;
					if ($opcodes[$catchBodyLast]['opcode'] == XC_JMP) {
						--$catchBodyLast;
					}

					$catchBlocks[$catchFirst] = array($catchOpLine, $catchBodyLast);

					$i = $catchFirst = $catchNext;
				}
				else {
					++$i;
				}
			}

			if ($opcodes[$tryRange[1]]['opcode'] == XC_JMP) {
				--$tryRange[1];
			}

			$this->beginComplexBlock($EX);
			echo $EX['indent'], "try {", PHP_EOL;
			$this->beginScope($EX);
			$this->recognizeAndDecompileClosedBlocks($tryRange);
			$this->endScope($EX);
			echo $EX['indent'], '}', PHP_EOL;
			if (!$catchBlocks) {
				printBacktrace();
				assert($catchBlocks);
			}
			foreach ($catchBlocks as $catchFirst => $catchInfo) {
				list($catchOpLine, $catchBodyLast) = $catchInfo;
				$catchBodyFirst = $catchOpLine + 1;
				$this->dasmBasicBlock(array($catchFirst, $catchOpLine, 'EX' => &$EX));
				$catchOp = &$opcodes[$catchOpLine];
				echo $EX['indent'], "catch ("
						, $this->stripNamespace(isset($catchOp['op1']['constant']) ? $catchOp['op1']['constant'] : str($this->getOpVal($catchOp['op1'], $EX)))
						, ' '
						, isset($catchOp['op2']['constant']) ? '$' . $catchOp['op2']['constant'] : str($this->getOpVal($catchOp['op2'], $EX))
						, ") {", PHP_EOL;
				unset($catchOp);

				$EX['lastBlock'] = null;
				$this->beginScope($EX);
				$this->recognizeAndDecompileClosedBlocks(array($catchBodyFirst, $catchBodyLast, 'EX' => &$EX));
				$this->endScope($EX);
				echo $EX['indent'], '}', PHP_EOL;
			}
			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function decompile_switch($range, &$EX, &$opcodes, &$firstOp, &$lastOp) // {{{
	{
		if ($firstOp['opcode'] == XC_CASE && !empty($lastOp['jmptos'])
		 || $firstOp['opcode'] == XC_JMP && !empty($firstOp['jmptos']) && $opcodes[$firstOp['jmptos'][0]]['opcode'] == XC_CASE && !empty($lastOp['jmptos'])
		) {
			$this->clearJmpInfo_brk_cont($range);
			$cases = array();
			$caseDefault = null;
			$caseOp = null;
			for ($i = $range[0]; $i <= $range[1]; ) {
				$op = $opcodes[$i];
				if ($op['opcode'] == XC_CASE) {
					if (!isset($caseOp)) {
						$caseOp = $op;
					}
					$jmpz = $opcodes[$i + 1];
					assert('$jmpz["opcode"] == XC_JMPZ');
					$caseNext = $jmpz['jmptos'][0];
					$cases[$i] = $caseNext - 1;
					$i = $caseNext;
				}
				else if ($op['opcode'] == XC_JMP && $op['jmptos'][0] >= $i) {
					// default
					$caseNext = $op['jmptos'][0];
					$caseDefault = $i;
					$cases[$i] = $caseNext - 1;
					$i = $caseNext;
				}
				else {
					++$i;
				}
			}

			$this->beginComplexBlock($EX);

			echo $EX['indent'], 'switch (', str($this->getOpVal($caseOp['op1'], $EX, true), $EX), ") {", PHP_EOL;
			$caseIsOut = false;
			foreach ($cases as $caseFirst => $caseLast) {
				if ($caseIsOut && empty($lastCaseFall)) {
					echo PHP_EOL;
				}

				$caseOp = $opcodes[$caseFirst];

				echo $EX['indent'];
				if ($caseOp['opcode'] == XC_CASE) {
					echo 'case ';
					echo str($this->getOpVal($caseOp['op2'], $EX), $EX);
					echo ':', PHP_EOL;

					$this->removeJmpInfo($EX, $caseFirst);
					++$caseFirst;

					assert('$opcodes[$caseFirst]["opcode"] == XC_JMPZ');
					$this->removeJmpInfo($EX, $caseFirst);
					++$caseFirst;
				}
				else {
					echo 'default';
					echo ':', PHP_EOL;

					assert('$opcodes[$caseFirst]["opcode"] == XC_JMP');
					$this->removeJmpInfo($EX, $caseFirst);
					++$caseFirst;
				}

				assert('$opcodes[$caseLast]["opcode"] == XC_JMP');
				$this->removeJmpInfo($EX, $caseLast);
				--$caseLast;
				switch ($opcodes[$caseLast]['opcode']) {
				case XC_BRK:
				case XC_CONT:
				case XC_GOTO:
					$lastCaseFall = false;
					break;

				default:
					$lastCaseFall = true;
				}

				$this->beginScope($EX);
				$this->recognizeAndDecompileClosedBlocks(array($caseFirst, $caseLast, 'EX' => &$EX));
				$this->endScope($EX);
				$caseIsOut = true;
			}
			echo $EX['indent'], '}', PHP_EOL;

			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function decompile_doWhile($range, &$EX, &$opcodes, &$firstOp, &$lastOp) // {{{
	{
		if ($lastOp['opcode'] == XC_JMPNZ && !empty($lastOp['jmptos'])
		 && $lastOp['jmptos'][0] == $range[0]) {
			$this->removeJmpInfo($EX, $range[1]);
			$this->clearJmpInfo_brk_cont($range);
			$this->beginComplexBlock($EX);

			echo $EX['indent'], "do {", PHP_EOL;
			$this->beginScope($EX);
			$this->recognizeAndDecompileClosedBlocks($range);
			$this->endScope($EX);
			echo $EX['indent'], "} while (", str($this->getOpVal($lastOp['op1'], $EX)), ');', PHP_EOL;

			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function decompile_while($range, &$EX, &$opcodes, &$firstOp, &$lastOp, &$firstJmpOp) // {{{
	{
		if ($firstJmpOp['opcode'] == XC_JMPZ
		 && $firstJmpOp['jmptos'][0] > $range[1]
		 && $lastOp['opcode'] == XC_JMP
		 && !empty($lastOp['jmptos']) && $lastOp['jmptos'][0] == $range[0]) {
			$this->removeJmpInfo($EX, $firstJmpOp['line']);
			$this->removeJmpInfo($EX, $range[1]);
			$this->beginComplexBlock($EX);

			ob_start();
			$this->beginScope($EX);
			$this->recognizeAndDecompileClosedBlocks($range);
			$this->endScope($EX);
			$body = ob_get_clean();

			echo $EX['indent'], "while (", str($this->getOpVal($firstJmpOp['op1'], $EX)), ") {", PHP_EOL;
			echo $body;
			echo $EX['indent'], '}', PHP_EOL;

			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function decompile_foreach($range, &$EX, &$opcodes, &$firstOp, &$lastOp, &$firstJmpOp, &$lastJmpOp) // {{{
	{
		if ($firstJmpOp['opcode'] == XC_FE_FETCH
		 && !empty($firstJmpOp['jmptos']) && $firstJmpOp['jmptos'][0] > $lastJmpOp['line']
		 && isset($lastJmpOp)
		 && $lastJmpOp['opcode'] == XC_JMP
		 && !empty($lastJmpOp['jmptos']) && $lastJmpOp['jmptos'][0] == $firstJmpOp['line']) {
			$this->removeJmpInfo($EX, $firstJmpOp['line']);
			$this->removeJmpInfo($EX, $lastJmpOp['line']);
			$this->clearJmpInfo_brk_cont($range);
			$this->beginComplexBlock($EX);

			ob_start();
			$this->beginScope($EX);
			$this->recognizeAndDecompileClosedBlocks($range);
			$this->endScope($EX);
			$body = ob_get_clean();

			$as = str(foldToCode($firstJmpOp['fe_as'], $EX), $EX);
			if (isset($firstJmpOp['fe_key'])) {
				$as = str($firstJmpOp['fe_key'], $EX) . ' => ' . $as;
			}

			echo $EX['indent'], "foreach (", str($firstJmpOp['fe_src'], $EX), " as $as) {", PHP_EOL;
			echo $body;
			echo $EX['indent'], '}', PHP_EOL;

			$this->endComplexBlock($EX);
			return true;
		}
	}
	// }}}
	function recognizeAndDecompileClosedBlocks($range) // {{{ decompile in a tree way
	{
		$EX = &$range['EX'];
		$opcodes = &$EX['opcodes'];

		$starti = $range[0];
		for ($i = $starti; $i <= $range[1]; ) {
			if (!empty($opcodes[$i]['jmpfroms']) || !empty($opcodes[$i]['jmptos'])) {
				$blockFirst = $i;
				$blockLast = -1;
				$j = $blockFirst;
				do {
					$op = $opcodes[$j];
					if (!empty($op['jmpfroms'])) {
						// care about jumping from blocks behind, not before
						foreach ($op['jmpfroms'] as $oplineNumber) {
							if ($oplineNumber <= $range[1] && $blockLast < $oplineNumber) {
								$blockLast = $oplineNumber;
							}
						}
					}
					if (!empty($op['jmptos'])) {
						$blockLast = max($blockLast, max($op['jmptos']) - 1);
					}
					++$j;
				} while ($j <= $blockLast);

				if ($blockLast > $range[1]) {
					fprintf(STDERR, "%d: \$blockLast(%d) > \$range[1](%d)\n", __LINE__, $blockLast, $range[1]);
					assert('$blockLast <= $range[1]');
					printBacktrace();
				}

				if ($blockLast >= $blockFirst) {
					if ($blockFirst > $starti) {
						$this->decompileBasicBlock(array($starti, $blockFirst - 1, 'EX' => &$EX));
					}
					if ($this->decompileComplexBlock(array($blockFirst, $blockLast, 'EX' => &$EX)) === false) {
						if ($EX['lastBlock'] == 'complex') {
							echo PHP_EOL;
						}
						$EX['lastBlock'] = null;
					}
					$starti = $blockLast + 1;
					$i = $starti;
				}
				else {
					++$i;
				}
			}
			else {
				++$i;
			}
		}
		if ($starti <= $range[1]) {
			$this->decompileBasicBlock(array($starti, $range[1], 'EX' => &$EX));
		}
	}
	// }}}
	function buildJmpInfo($range) // {{{ build jmpfroms/jmptos to op_array
	{
		$op_array = &$range['EX']['op_array'];
		$opcodes = &$range['EX']['opcodes'];
		for ($i = $range[0]; $i <= $range[1]; $i++) {
			$op = &$opcodes[$i];
			switch ($op['opcode']) {
			case XC_CONT:
			case XC_BRK:
				$jmpTo = null;
				if ($op['op2']['op_type'] == XC_IS_CONST && is_int($op['op2']['constant'])) {
					$nestedLevel = $op['op2']['constant'];
					$arrayOffset = $op['op1']['opline_num'];
					// zend_brk_cont
					while ($nestedLevel-- > 0) {
						if ($arrayOffset == -1) {
							$jmpTo = null;
							break;
						}
						if (!isset($op_array['brk_cont_array'][$arrayOffset])) {
							fprintf(STDERR, "%d: brk/cont not found at #$i\n", __LINE__);
							break;
						}
						$jmpTo = $op_array['brk_cont_array'][$arrayOffset];
						$arrayOffset = $jmpTo['parent'];
					}
				}

				$op['jmptos'] = array();
				if (isset($jmpTo)) {
					$jmpTo = $jmpTo[$op['opcode'] == XC_CONT ? 'cont' : 'brk'];
					$op['jmptos'][] = $jmpTo;
					$opcodes[$jmpTo]['jmpfroms'][] = $i;
				}
				break;

			case XC_GOTO:
				$target = $op['op1']['var'];
				if (!isset($opcodes[$target])) {
					fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
					break;
				}
				$op['goto'] = $target;
				$opcodes[$target]['gofrom'][] = $i;
				break;

			case XC_JMP:
				$target = $op['op1']['var'];
				if (!isset($opcodes[$target])) {
					fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
					break;
				}
				$op['jmptos'] = array($target);
				$opcodes[$target]['jmpfroms'][] = $i;
				break;

			case XC_JMPZNZ:
				$jmpz = $op['op2']['opline_num'];
				$jmpnz = $op['extended_value'];
				if (!isset($opcodes[$jmpz])) {
					fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
					break;
				}
				if (!isset($opcodes[$jmpnz])) {
					fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
					break;
				}
				$op['jmptos'] = array($jmpz, $jmpnz);
				$opcodes[$jmpz]['jmpfroms'][] = $i;
				$opcodes[$jmpnz]['jmpfroms'][] = $i;
				break;

			case XC_JMPZ:
			case XC_JMPNZ:
			case XC_JMPZ_EX:
			case XC_JMPNZ_EX:
			// case XC_JMP_SET:
			// case XC_JMP_SET_VAR:
			// case XC_FE_RESET:
			case XC_FE_FETCH:
			// case XC_JMP_NO_CTOR:
				$target = $op['op2']['opline_num'];
				if (!isset($opcodes[$target])) {
					fprintf(STDERR, "%d: missing jump target at #$i" . PHP_EOL, __LINE__);
					break;
				}
				$op['jmptos'] = array($target);
				$opcodes[$target]['jmpfroms'][] = $i;
				break;

			/*
			case XC_RETURN:
				$op['jmptos'] = array();
				break;
			*/

			case XC_CASE:
				// just to link together
				$op['jmptos'] = array($i + 2);
				$opcodes[$i + 2]['jmpfroms'][] = $i;
				break;

			case XC_CATCH:
				$catchNext = $op['extended_value'];
				$catchBegin = $opcodes[$i - 1]['opcode'] == XC_FETCH_CLASS ? $i - 1 : $i;
				$opcodes[$catchBegin]['jmptos'] = array($catchNext);
				$opcodes[$catchNext]['jmpfroms'][] = $catchBegin;
				break;
			}
			/*
			if (!empty($op['jmptos']) || !empty($op['jmpfroms'])) {
				echo $i, "\t", xcache_get_opcode($op['opcode']), PHP_EOL;
			}
			// */
		}
		unset($op);
		if (isset($op_array['try_catch_array'])) {
			foreach ($op_array['try_catch_array'] as $try_catch_element) {
				$catch_op = $try_catch_element['catch_op'];
				$opcodes[$catch_op]['isCatchBegin'] = true;
			}
			foreach ($op_array['try_catch_array'] as $try_catch_element) {
				$catch_op = $try_catch_element['catch_op'];
				$try_op = $try_catch_element['try_op'];
				do {
					$opcodes[$try_op]['jmpfroms'][] = $catch_op;
					$opcodes[$catch_op]['jmptos'][] = $try_op;
					if ($opcodes[$catch_op]['opcode'] == XC_CATCH) {
						$catch_op = $opcodes[$catch_op]['extended_value'];
					}
					else if ($catch_op + 1 <= $range[1] && $opcodes[$catch_op + 1]['opcode'] == XC_CATCH) {
						$catch_op = $opcodes[$catch_op + 1]['extended_value'];
					}
					else {
						break;
					}
				} while ($catch_op <= $range[1] && empty($opcodes[$catch_op]['isCatchBegin']));
			}
		}
	}
	// }}}
	function clearJmpInfo_brk_cont($range) // {{{ clear jmpfroms/jmptos for BRK/CONT relative to this range only
	{
		$opcodes = &$range['EX']['opcodes'];
		for ($i = $range[0]; $i <= $range[1]; $i++) {
			$op = &$opcodes[$i];
			switch ($op['opcode']) {
			case XC_CONT:
			case XC_BRK:
				if (!empty($op['jmptos'])) {
					if ($op['jmptos'][0] == $range[0]
					 || $op['jmptos'][0] == $range[1] + 1) {
						$this->removeJmpInfo($range['EX'], $i);
					}
				}
				break;
			}
		}
		unset($op);
	}
	// }}}
	function &dop_array($op_array, $indent = '') // {{{
	{
		$op_array['opcodes'] = $this->fixOpCode($op_array['opcodes'], true, $indent == '' ? 1 : null);

		$opcodes = &$op_array['opcodes'];

		$EX = array();
		$EX['Ts'] = $this->outputPhp ? array() : null;
		$EX['indent'] = $indent;
		$EX['op_array'] = &$op_array;
		$EX['opcodes'] = &$opcodes;
		// func call
		$EX['object'] = null;
		$EX['called_scope'] = null;
		$EX['fbc'] = null;
		$EX['argstack'] = array();
		$EX['arg_types_stack'] = array();
		$EX['scopeStack'] = array();
		$EX['silence'] = 0;
		$EX['recvs'] = array();
		$EX['uses'] = array();
		$EX['lastBlock'] = null;
		$EX['value2constant'] = array();
		if (isset($this->activeMethod)) {
			$EX['value2constant'][$this->activeMethod] = '__METHOD__';
		}
		if (isset($this->activeFunction)) {
			$EX['value2constant'][$this->activeFunction] = '__FUNCTION__';
		}

		$range = array(0, count($opcodes) - 1, 'EX' => &$EX);
		for ($i = $range[0]; $i <= $range[1]; $i++) {
			$opcodes[$i]['line'] = $i;
		}
		$this->buildJmpInfo($range);

		if ($this->outputOpcode) {
			$this->keepTs = true;
			$this->dumpRange($range);
			$this->keepTs = false;
		}
		if ($this->outputPhp) {
			// decompile in a tree way
			$this->recognizeAndDecompileClosedBlocks($range);
		}
		return $EX;
	}
	// }}}
	function dasmBasicBlock($range) // {{{
	{
		$EX = &$range['EX'];
		$T = &$EX['Ts'];
		$opcodes = &$EX['opcodes'];
		$lastphpop = null;

		for ($i = $range[0]; $i <= $range[1]; $i++) {
			// {{{ prepair
			$op = &$opcodes[$i];
			$opc = $op['opcode'];
			if ($opc == XC_NOP) {
				$this->usedOps[$opc] = true;
				continue;
			}

			$op1 = $op['op1'];
			$op2 = $op['op2'];
			$res = $op['result'];
			$ext = $op['extended_value'];

			$opname = xcache_get_opcode($opc);

			if ($opname == 'UNDEF' || !isset($opname)) {
				echo '// UNDEF OP:';
				$this->dumpOp($op, $EX);
				continue;
			}
			// echo $i, ' '; $this->dumpOp($op, $EX); //var_dump($op);

			$resvar = null;
			unset($curResVar);
			if (array_key_exists($res['var'], $T)) {
				$curResVar = &$T[$res['var']];
			}
			if ((ZEND_ENGINE_2_4 ? ($res['op_type'] & EXT_TYPE_UNUSED) : ($res['EA.type'] & EXT_TYPE_UNUSED)) || $res['op_type'] == XC_IS_UNUSED) {
				$istmpres = false;
			}
			else {
				$istmpres = true;
			}
			// }}}
			// echo $opname, PHP_EOL;

			$notHandled = false;
			switch ($opc) {
			case XC_NEW: // {{{
				array_push($EX['arg_types_stack'], array($EX['fbc'], $EX['object'], $EX['called_scope']));
				$EX['object'] = $istmpres ? (int) $res['var'] : null;
				$EX['called_scope'] = null;
				$EX['fbc'] = 'new ' . $this->stripNamespace(isset($op1['constant']) ? $op1['constant'] : $this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_THROW: // {{{
				$resvar = 'throw ' . str($this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_CLONE: // {{{
				$resvar = 'clone ' . str($this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_CATCH: // {{{
				break;
				// }}}
			case XC_INSTANCEOF: // {{{
				$resvar = str($this->getOpVal($op1, $EX)) . ' instanceof ' . $this->stripNamespace($this->getOpVal($op2, $EX));
				break;
				// }}}
			case XC_FETCH_CLASS: // {{{
				if ($op2['op_type'] == XC_IS_UNUSED) {
					switch (($ext & (defined('ZEND_FETCH_CLASS_MASK') ? ZEND_FETCH_CLASS_MASK : 0xFF))) {
					case ZEND_FETCH_CLASS_SELF:
						$class = 'self';
						break;
					case ZEND_FETCH_CLASS_PARENT:
						$class = 'parent';
						break;
					case ZEND_FETCH_CLASS_STATIC:
						$class = 'static';
						break;
					}
					$istmpres = true;
				}
				else {
					$class = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
				}
				$resvar = $class;
				break;
				// }}}
			case XC_FETCH_CONSTANT: // {{{
				if ($op1['op_type'] == XC_IS_UNUSED) {
					$resvar = $this->stripNamespace($op2['constant']);
					break;
				}

				if ($op1['op_type'] == XC_IS_CONST) {
					if (!ZEND_ENGINE_2) {
						$resvar = $op1['constant'];
						break;
					}
					$resvar = $this->stripNamespace($op1['constant']);
				}
				else {
					$resvar = $this->getOpVal($op1, $EX);
				}

				$resvar = str($resvar) . '::' . unquoteName($this->getOpVal($op2, $EX));
				break;
				// }}}
				// {{{ case FETCH_*
			case XC_FETCH_R:
			case XC_FETCH_W:
			case XC_FETCH_RW:
			case XC_FETCH_FUNC_ARG:
			case XC_FETCH_UNSET:
			case XC_FETCH_IS:
				$fetchType = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2[!ZEND_ENGINE_2 ? 'fetch_type' : 'EA.type'];
				$name = isset($op1['constant']) ? $op1['constant'] : unquoteName($this->getOpVal($op1, $EX), $EX);
				if ($fetchType == ZEND_FETCH_STATIC_MEMBER) {
					$class = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
					$rvalue = $this->stripNamespace($class) . '::$' . $name;
				}
				else {
					$rvalue = isset($op1['constant']) ? $op1['constant'] : $this->getOpVal($op1, $EX);
					$globalName = xcache_is_autoglobal($name) ? "\$$name" : "\$GLOBALS[" . str($this->getOpVal($op1, $EX), $EX) . "]";
					$rvalue = new Decompiler_Fetch($rvalue, $fetchType, $globalName);
				}

				if ($res['op_type'] != XC_IS_UNUSED) {
					$resvar = $rvalue;
				}
				break;
				// }}}
			case XC_UNSET_VAR: // {{{
				$fetchType = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2['EA.type'];
				if ($fetchType == ZEND_FETCH_STATIC_MEMBER) {
					$class = isset($op2['constant']) ? $op2['constant'] /* PHP5.3- */ : $this->getOpVal($op2, $EX);
					$rvalue = $this->stripNamespace($class) . '::$' . $op1['constant'];
				}
				else {
					$rvalue = isset($op1['constant']) ? '$' . $op1['constant'] /* PHP5.1- */ : $this->getOpVal($op1, $EX);
				}

				$op['php'] = "unset(" . str($rvalue, $EX) . ")";
				$lastphpop = &$op;
				break;
				// }}}
				// {{{ case FETCH_DIM_*
			case XC_FETCH_DIM_TMP_VAR:
			case XC_FETCH_DIM_R:
			case XC_FETCH_DIM_W:
			case XC_FETCH_DIM_RW:
			case XC_FETCH_DIM_FUNC_ARG:
			case XC_FETCH_DIM_UNSET:
			case XC_FETCH_DIM_IS:
			case XC_ASSIGN_DIM:
			case XC_UNSET_DIM:
			case XC_UNSET_DIM_OBJ:
			case XC_UNSET_OBJ:
				$src = $this->getOpVal($op1, $EX);
				if (is_a($src, "Decompiler_ForeachBox")) {
					assert($opc == XC_FETCH_DIM_TMP_VAR);
					if (ZEND_ENGINE_2) {
						$src = clone($src);
					}
					else {
						$src = new Decompiler_ForeachBox($src->obj);
					}
					$src->iskey = $op2['constant'];
					$resvar = $src;
					break;
				}

				if (is_a($src, "Decompiler_DimBox")) {
					$dimbox = $src;
				}
				else {
					if (!is_a($src, "Decompiler_ListBox")) {
						$op1val = $this->getOpVal($op1, $EX);
						$list = new Decompiler_List(isset($op1val) ? $op1val : '$this');

						$src = new Decompiler_ListBox($list);
						if (!isset($op1['var'])) {
							$this->dumpOp($op, $EX);
							var_dump($op);
							die('missing var');
						}
						$T[$op1['var']] = $src;
						unset($list);
					}
					$dim = new Decompiler_Dim($src);
					$src->obj->dims[] = &$dim;

					$dimbox = new Decompiler_DimBox($dim);
				}
				$dim = &$dimbox->obj;
				$dim->offsets[] = $this->getOpVal($op2, $EX);
				/* TODO: use type mask */
				if ($ext == ZEND_FETCH_ADD_LOCK) {
					$src->obj->everLocked = true;
				}
				else if ($ext == ZEND_FETCH_STANDARD) {
					$dim->isLast = true;
				}
				if ($opc == XC_UNSET_OBJ) {
					$dim->isObject = true;
				}
				else if ($opc == XC_UNSET_DIM_OBJ) {
					$dim->isObject = ZEND_ENGINE_2 ? $ext == ZEND_UNSET_OBJ : false /* cannot distingue */;
				}
				unset($dim);
				$rvalue = $dimbox;
				unset($dimbox);

				if ($opc == XC_ASSIGN_DIM) {
					$lvalue = $rvalue;
					++ $i;
					$rvalue = $this->getOpVal($opcodes[$i]['op1'], $EX);
					$resvar = str($lvalue, $EX) . ' = ' . str($rvalue);
				}
				else if ($opc == XC_UNSET_DIM || $opc == XC_UNSET_OBJ || $opc == XC_UNSET_DIM_OBJ) {
					$op['php'] = "unset(" . str($rvalue, $EX) . ")";
					$lastphpop = &$op;
				}
				else if ($res['op_type'] != XC_IS_UNUSED) {
					$resvar = $rvalue;
				}
				break;
				// }}}
			case XC_ASSIGN: // {{{
				$lvalue = $this->getOpVal($op1, $EX);
				$rvalue = $this->getOpVal($op2, $EX);
				if (is_a($rvalue, 'Decompiler_ForeachBox')) {
					$type = $rvalue->iskey ? 'fe_key' : 'fe_as';
					$rvalue->obj[$type] = $lvalue;
					unset($T[$op2['var']]);
					break;
				}
				if (is_a($rvalue, "Decompiler_DimBox")) {
					$dim = &$rvalue->obj;
					$dim->assign = $lvalue;
					if ($dim->isLast) {
						$resvar = foldToCode($dim->value, $EX);
					}
					unset($dim);
					break;
				}
				if (is_a($rvalue, 'Decompiler_Fetch')) {
					$src = str($rvalue->src, $EX);
					$name = unquoteName($src);
					if ('$' . $name == $lvalue) {
						switch ($rvalue->fetchType) {
						case ZEND_FETCH_STATIC:
							$statics = &$EX['op_array']['static_variables'];
							if ((xcache_get_type($statics[$name]) & IS_LEXICAL_VAR)) {
								$EX['uses'][] = str($lvalue);
								unset($statics);
								break 2;
							}
							unset($statics);
						}
					}
				}
				$resvar = new Decompiler_BinaryOp($this, $lvalue, XC_ASSIGN, $rvalue);
				break;
				// }}}
			case XC_ASSIGN_REF: // {{{
				$lvalue = $this->getOpVal($op1, $EX);
				$rvalue = $this->getOpVal($op2, $EX);
				if (is_a($rvalue, 'Decompiler_Fetch')) {
					$src = str($rvalue->src, $EX);
					if ('$' . unquoteName($src) == $lvalue) {
						switch ($rvalue->fetchType) {
						case ZEND_FETCH_GLOBAL:
						case ZEND_FETCH_GLOBAL_LOCK:
							$resvar = 'global ' . $lvalue;
							break 2;
						case ZEND_FETCH_STATIC:
							$statics = &$EX['op_array']['static_variables'];
							$name = unquoteName($src);
							if ((xcache_get_type($statics[$name]) & IS_LEXICAL_REF)) {
								$EX['uses'][] = '&' . str($lvalue);
								unset($statics);
								break 2;
							}

							$resvar = 'static ' . $lvalue;
							if (isset($statics[$name])) {
								$var = $statics[$name];
								$resvar .= ' = ';
								$resvar .= str(value($var, $EX), $EX);
							}
							unset($statics);
							break 2;
						default:
						}
					}
				}
				// TODO: PHP_6 global
				$resvar = new Decompiler_BinaryOp($this, $lvalue, XC_ASSIGN_REF, $rvalue);
				break;
				// }}}
			// {{{ case FETCH_OBJ_*
			case XC_FETCH_OBJ_R:
			case XC_FETCH_OBJ_W:
			case XC_FETCH_OBJ_RW:
			case XC_FETCH_OBJ_FUNC_ARG:
			case XC_FETCH_OBJ_UNSET:
			case XC_FETCH_OBJ_IS:
			case XC_ASSIGN_OBJ:
				$obj = $this->getOpVal($op1, $EX);
				if (!isset($obj)) {
					$obj = '$this';
				}
				$rvalue = str($obj) . "->" . unquoteVariableName($this->getOpVal($op2, $EX), $EX);
				if ($res['op_type'] != XC_IS_UNUSED) {
					$resvar = $rvalue;
				}
				if ($opc == XC_ASSIGN_OBJ) {
					++ $i;
					$lvalue = $rvalue;
					$rvalue = $this->getOpVal($opcodes[$i]['op1'], $EX);
					$resvar = "$lvalue = " . str($rvalue);
				}
				break;
				// }}}
			case XC_ISSET_ISEMPTY_DIM_OBJ:
			case XC_ISSET_ISEMPTY_PROP_OBJ:
			case XC_ISSET_ISEMPTY:
			case XC_ISSET_ISEMPTY_VAR: // {{{
				if ($opc == XC_ISSET_ISEMPTY_VAR) {
					$rvalue = $this->getOpVal($op1, $EX);
					// for < PHP_5_3
					if ($op1['op_type'] == XC_IS_CONST) {
						$rvalue = '$' . unquoteVariableName($this->getOpVal($op1, $EX));
					}
					$fetchtype = defined('ZEND_FETCH_TYPE_MASK') ? ($ext & ZEND_FETCH_TYPE_MASK) : $op2['EA.type'];
					if ($fetchtype == ZEND_FETCH_STATIC_MEMBER) {
						$class = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
						$rvalue = $this->stripNamespace($class) . '::' . unquoteName($rvalue, $EX);
					}
				}
				else if ($opc == XC_ISSET_ISEMPTY) {
					$rvalue = $this->getOpVal($op1, $EX);
				}
				else {
					$container = $this->getOpVal($op1, $EX);
					$dim = $this->getOpVal($op2, $EX);
					if ($opc == XC_ISSET_ISEMPTY_PROP_OBJ) {
						if (!isset($container)) {
							$container = '$this';
						}
						$rvalue = str($container, $EX) . "->" . unquoteVariableName($dim);
					}
					else {
						$rvalue = str($container, $EX) . '[' . str($dim) .']';
					}
				}

				switch (((!ZEND_ENGINE_2 ? $op['op2']['var'] /* constant */ : $ext) & ZEND_ISSET_ISEMPTY_MASK)) {
				case ZEND_ISSET:
					$rvalue = "isset(" . str($rvalue) . ")";
					break;
				case ZEND_ISEMPTY:
					$rvalue = "empty(" . str($rvalue) . ")";
					break;
				}
				$resvar = $rvalue;
				break;
				// }}}
			case XC_SEND_VAR_NO_REF:
			case XC_SEND_VAL:
			case XC_SEND_REF:
			case XC_SEND_VAR: // {{{
				$ref = (!ZEND_ENGINE_2_4 && $opc == XC_SEND_REF ? '&' : '');
				$EX['argstack'][] = $ref . str($this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_INIT_STATIC_METHOD_CALL:
			case XC_INIT_METHOD_CALL: // {{{
				array_push($EX['arg_types_stack'], array($EX['fbc'], $EX['object'], $EX['called_scope']));
				if ($opc == XC_INIT_STATIC_METHOD_CALL) {
					$EX['object'] = null;
					$EX['called_scope'] = $this->stripNamespace(isset($op1['constant']) ? $op1['constant'] : $this->getOpVal($op1, $EX));
				}
				else {
					$obj = $this->getOpVal($op1, $EX);
					if (!isset($obj)) {
						$obj = '$this';
					}
					$EX['object'] = $obj;
					$EX['called_scope'] = null;
				}
				if ($res['op_type'] != XC_IS_UNUSED) {
					$resvar = '$obj call$';
				}

				$EX['fbc'] = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
				if (!isset($EX['fbc'])) {
					$EX['fbc'] = '__construct';
				}
				break;
				// }}}
			case XC_INIT_NS_FCALL_BY_NAME:
			case XC_INIT_FCALL_BY_NAME: // {{{
				if (!ZEND_ENGINE_2 && ($ext & ZEND_CTOR_CALL)) {
					break;
				}
				array_push($EX['arg_types_stack'], array($EX['fbc'], $EX['object'], $EX['called_scope']));
				if (!ZEND_ENGINE_2 && ($ext & ZEND_MEMBER_FUNC_CALL)) {
					if (isset($op1['constant'])) {
						$EX['object'] = null;
						$EX['called_scope'] = $this->stripNamespace($op1['constant']);
					}
					else {
						$EX['object'] = $this->getOpVal($op1, $EX);
						$EX['called_scope'] = null;
					}
				}
				else {
					$EX['object'] = null;
					$EX['called_scope'] = null;
				}
				$EX['fbc'] = isset($op2['constant']) ? $op2['constant'] : $this->getOpVal($op2, $EX);
				break;
				// }}}
			case XC_INIT_FCALL_BY_FUNC: // {{{ deprecated even in PHP 4?
				$EX['object'] = null;
				$EX['called_scope'] = null;
				$which = $op1['var'];
				$EX['fbc'] = $EX['op_array']['funcs'][$which]['name'];
				break;
				// }}}
			case XC_DO_FCALL_BY_FUNC:
				$which = $op1['var'];
				$fname = $EX['op_array']['funcs'][$which]['name'];
				$args = $this->popargs($EX, $ext);
				$resvar = $fname . "($args)";
				break;
			case XC_DO_FCALL:
				$fname = unquoteName($this->getOpVal($op1, $EX), $EX);
				$args = $this->popargs($EX, $ext);
				$resvar = $fname . "($args)";
				break;
			case XC_DO_FCALL_BY_NAME: // {{{
				$object = null;

				if (!is_int($EX['object'])) {
					$object = $EX['object'];
				}

				$args = $this->popargs($EX, $ext);

				$prefix = (isset($object) ? str($object) . '->' : '' )
					. (isset($EX['called_scope']) ? str($EX['called_scope']) . '::' : '');
				$resvar = $prefix
					. (!$prefix ? $this->stripNamespace($EX['fbc']) : str($EX['fbc']))
					. "($args)";
				unset($args);

				if (is_int($EX['object'])) {
					$T[$EX['object']] = $resvar;
					$resvar = null;
				}
				list($EX['fbc'], $EX['object'], $EX['called_scope']) = array_pop($EX['arg_types_stack']);
				break;
				// }}}
			case XC_VERIFY_ABSTRACT_CLASS: // {{{
				//unset($T[$op1['var']]);
				break;
				// }}}
			case XC_DECLARE_CLASS:
			case XC_DECLARE_INHERITED_CLASS:
			case XC_DECLARE_INHERITED_CLASS_DELAYED: // {{{
				$key = $op1['constant'];
				// possible missing tailing \0 (outside of the string)
				$key = substr($key . ".", 0, strlen($key));
				if (!isset($this->dc['class_table'][$key])) {
					echo $EX['indent'], "/* class not found: ", $key, ", existing classes are:", PHP_EOL;
					var_dump(array_keys($this->dc['class_table']));
					echo "*/", PHP_EOL;
					break;
				}
				$class = &$this->dc['class_table'][$key];
				$this->detectNamespace($class['name']);

				if (!isset($class['name'])) {
					$class['name'] = unquoteName($this->getOpVal($op2, $EX), $EX);
				}
				if ($opc == XC_DECLARE_INHERITED_CLASS || $opc == XC_DECLARE_INHERITED_CLASS_DELAYED) {
					if (ZEND_ENGINE_2_5) {
						$ext = (0xffffffff - $ext + 1) / XC_SIZEOF_TEMP_VARIABLE - 1;
					}
					else {
						$ext /= XC_SIZEOF_TEMP_VARIABLE;
					}
					$class['parent'] = $T[$ext];
					unset($T[$ext]);
				}
				else {
					$class['parent'] = null;
				}

				for (;;) {
					if ($i + 1 <= $range[1]
					 && $opcodes[$i + 1]['opcode'] == XC_ADD_INTERFACE
					 && $opcodes[$i + 1]['op1']['var'] == $res['var']) {
						// continue
					}
					else if ($i + 2 <= $range[1]
					 && $opcodes[$i + 2]['opcode'] == XC_ADD_INTERFACE
					 && $opcodes[$i + 2]['op1']['var'] == $res['var']
					 && $opcodes[$i + 1]['opcode'] == XC_FETCH_CLASS) {
						// continue
					}
					else {
						break;
					}
					$this->usedOps[XC_ADD_INTERFACE] = true;

					$fetchop = &$opcodes[$i + 1];
					$interface = $this->stripNamespace(unquoteName($this->getOpVal($fetchop['op2'], $EX), $EX));
					$addop = &$opcodes[$i + 2];
					$class['interfaces'][$addop['extended_value']] = $interface;
					unset($fetchop, $addop);
					$i += 2;
				}
				if ($EX['lastBlock'] == 'complex') {
					echo PHP_EOL;
				}
				$EX['lastBlock'] = null;
				$this->activeClass = $class['name'];
				$this->dclass($class, $EX['indent']);
				$this->activeClass = null;
				echo PHP_EOL;
				unset($class);
				break;
				// }}}
			case XC_INIT_STRING: // {{{
				$resvar = "''";
				break;
				// }}}
			case XC_ADD_CHAR:
			case XC_ADD_STRING:
			case XC_ADD_VAR: // {{{
				$op1val = $this->getOpVal($op1, $EX);
				$op2val = $this->getOpVal($op2, $EX);
				switch ($opc) {
				case XC_ADD_CHAR:
					$op2val = value(chr(str($op2val)), $EX);
					break;
				case XC_ADD_STRING:
					break;
				case XC_ADD_VAR:
					break;
				}
				if (str($op1val) == "''") {
					$rvalue = $op2val;
				}
				else if (str($op2val) == "''") {
					$rvalue = $op1val;
				}
				else {
					$rvalue = str($op1val) . ' . ' . str($op2val);
				}
				$resvar = $rvalue;
				// }}}
				break;
			case XC_PRINT: // {{{
				$op1val = $this->getOpVal($op1, $EX);
				$resvar = "print(" . str($op1val) . ")";
				break;
				// }}}
			case XC_ECHO: // {{{
				$op1val = $this->getOpVal($op1, $EX);
				$resvar = "echo " . str($op1val);
				break;
				// }}}
			case XC_EXIT: // {{{
				$op1val = $this->getOpVal($op1, $EX);
				$resvar = "exit(" . str($op1val) . ")";
				break;
				// }}}
			case XC_INIT_ARRAY:
			case XC_ADD_ARRAY_ELEMENT: // {{{
				$rvalue = $this->getOpVal($op1, $EX, true);
				$assoc = $this->getOpVal($op2, $EX);
				$element = array($assoc, $rvalue, empty($ext) ? '' : '&');

				if ($opc == XC_INIT_ARRAY) {
					$resvar = new Decompiler_Array();

					if (isset($rvalue)) {
						$resvar->value[] = $element;
					}
				}
				else {
					$curResVar->value[] = $element;
				}
				unset($element);
				break;
				// }}}
			case XC_QM_ASSIGN:
			case XC_QM_ASSIGN_VAR: // {{{
				if (isset($curResVar) && is_a($curResVar, 'Decompiler_BinaryOp')) {
					$curResVar->op2 = $this->getOpVal($op1, $EX);
				}
				else {
					$resvar = $this->getOpVal($op1, $EX);
				}
				break;
				// }}}
			case XC_BOOL: // {{{
				$resvar = /*'(bool) ' .*/ $this->getOpVal($op1, $EX);
				break;
				// }}}
			case XC_GENERATOR_RETURN:
			case XC_RETURN_BY_REF:
			case XC_RETURN: // {{{
				$resvar = "return " . str($this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_INCLUDE_OR_EVAL: // {{{
				$type = ZEND_ENGINE_2_4 ? $ext : $op2['var']; // hack
				$keyword = $this->includeTypes[$type];
				$rvalue = str($this->getOpVal($op1, $EX));
				if ($type == ZEND_EVAL) {
					$resvar = "$keyword($rvalue)";
				}
				else {
					$resvar = "$keyword $rvalue";
				}
				break;
				// }}}
			case XC_FE_RESET: // {{{
				$resvar = $this->getOpVal($op1, $EX);
				break;
				// }}}
			case XC_FE_FETCH: // {{{
				$op['fe_src'] = $this->getOpVal($op1, $EX, true);
				$fe = new Decompiler_ForeachBox($op);
				$fe->iskey = false;

				if (ZEND_ENGINE_2_1) {
					// save current first
					$T[$res['var']] = $fe;

					// move to next opcode
					++ $i;
					assert($opcodes[$i]['opcode'] == XC_OP_DATA);
					$fe = new Decompiler_ForeachBox($op);
					$fe->iskey = true;

					$res = $opcodes[$i]['result'];
				}

				$resvar = $fe;
				break;
				// }}}
			case XC_YIELD: // {{{
				$resvar = 'yield ' . str($this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_SWITCH_FREE: // {{{
				if (isset($T[$op1['var']])) {
					$this->beginComplexBlock($EX);
					echo $EX['indent'], 'switch (', str($this->getOpVal($op1, $EX)), ") {", PHP_EOL;
					echo $EX['indent'], '}', PHP_EOL;
					$this->endComplexBlock($EX);
				}
				break;
				// }}}
			case XC_FREE: // {{{
				$free = $T[$op1['var']];
				if (!is_a($free, 'Decompiler_Box')) {
					$op['php'] = is_object($free) ? $free : $this->unquote($free, '(', ')');
					$lastphpop = &$op;
				}
				unset($T[$op1['var']], $free);
				break;
				// }}}
			case XC_JMP_NO_CTOR:
				break;
			case XC_JMPZ_EX: // and
			case XC_JMPNZ_EX: // or
				$resvar = $this->getOpVal($op1, $EX);
				break;

			case XC_JMPNZ: // while
			case XC_JMPZNZ: // for
			case XC_JMPZ: // {{{
				break;
				// }}}
			case XC_CONT:
			case XC_BRK:
				$resvar = $opc == XC_CONT ? 'continue' : 'break';
				$count = str($this->getOpVal($op2, $EX));
				if ($count != '1') {
					$resvar .= ' ' . $count;
				}
				break;
			case XC_GOTO:
				$resvar = 'goto label' . $op['op1']['var'];
				$istmpres = false;
				break;

			case XC_JMP: // {{{
				break;
				// }}}
			case XC_CASE:
				// $switchValue = $this->getOpVal($op1, $EX);
				$caseValue = $this->getOpVal($op2, $EX);
				$resvar = $caseValue;
				break;
			case XC_RECV_INIT:
			case XC_RECV:
				$offset = isset($op1['var']) ? $op1['var'] : $op1['constant'];
				$lvalue = $this->getOpVal($op['result'], $EX);
				if ($opc == XC_RECV_INIT) {
					$default = value($op['op2']['constant'], $EX);
				}
				else {
					$default = null;
				}
				$EX['recvs'][$offset] = array($lvalue, $default);
				break;
			case XC_POST_DEC:
			case XC_POST_INC:
			case XC_POST_DEC_OBJ:
			case XC_POST_INC_OBJ:
			case XC_PRE_DEC:
			case XC_PRE_INC:
			case XC_PRE_DEC_OBJ:
			case XC_PRE_INC_OBJ: // {{{
				$flags = array_flip(explode('_', $opname));
				if (isset($flags['OBJ'])) {
					$resvar = str($this->getOpVal($op1, $EX)) . '->' . $op2['constant'];
				}
				else {
					$resvar = str($this->getOpVal($op1, $EX));
				}
				$opstr = isset($flags['DEC']) ? '--' : '++';
				if (isset($flags['POST'])) {
					$resvar .= $opstr;
				}
				else {
					$resvar = "$opstr$resvar";
				}
				break;
				// }}}

			case XC_BEGIN_SILENCE: // {{{
				$EX['silence']++;
				break;
				// }}}
			case XC_END_SILENCE: // {{{
				$EX['silence']--;
				$lastresvar = '@' . str($lastresvar, $EX);
				break;
				// }}}
			case XC_CAST: // {{{
				$type = $ext;
				static $type2cast = array(
						IS_LONG   => '(int)',
						IS_DOUBLE => '(double)',
						IS_STRING => '(string)',
						IS_ARRAY  => '(array)',
						IS_OBJECT => '(object)',
						IS_BOOL   => '(bool)',
						IS_NULL   => '(unset)',
						);
				assert(isset($type2cast[$type]));
				$cast = $type2cast[$type];
				$resvar = $cast . ' ' . str($this->getOpVal($op1, $EX));
				break;
				// }}}
			case XC_EXT_STMT:
			case XC_EXT_FCALL_BEGIN:
			case XC_EXT_FCALL_END:
			case XC_EXT_NOP:
			case XC_INIT_CTOR_CALL:
				break;
			case XC_DECLARE_FUNCTION:
				$key = $op1['constant'];
				// possible missing tailing \0 (outside of the string)
				$key = substr($key . ".", 0, strlen($key));
				$this->dfunction($this->dc['function_table'][$key], $EX['indent']);
				break;
			case XC_DECLARE_LAMBDA_FUNCTION: // {{{
				ob_start();
				$key = $op1['constant'];
				// possible missing tailing \0 (outside of the string)
				$key = substr($key . ".", 0, strlen($key));
				$this->dfunction($this->dc['function_table'][$key], $EX['indent']);
				$resvar = ob_get_clean();
				$istmpres = true;
				break;
				// }}}
			case XC_DECLARE_CONST:
				$name = $this->stripNamespace(unquoteName($this->getOpVal($op1, $EX), $EX));
				$value = str($this->getOpVal($op2, $EX));
				$resvar = 'const ' . $name . ' = ' . $value;
				break;
			case XC_DECLARE_FUNCTION_OR_CLASS:
				/* always removed by compiler */
				break;
			case XC_TICKS:
				$lastphpop['ticks'] = ZEND_ENGINE_2_4 ? $ext : $this->getOpVal($op1, $EX);
				// $EX['tickschanged'] = true;
				break;
			case XC_RAISE_ABSTRACT_ERROR:
				// abstract function body is empty, don't need this code
				break;
			case XC_USER_OPCODE:
				echo '// ZEND_USER_OPCODE, impossible to decompile';
				break;
			case XC_OP_DATA:
				break;
			default: // {{{
				$call = array(&$this, $opname);
				if (is_callable($call)) {
					$this->usedOps[$opc] = true;
					$this->{$opname}($op, $EX);
				}
				else if (isset($this->binaryOp[$opc])) { // {{{
					$this->usedOps[$opc] = true;
					$op1val = $this->getOpVal($op1, $EX);
					$op2val = $this->getOpVal($op2, $EX);
					$rvalue = new Decompiler_BinaryOp($this, $op1val, $opc, $op2val);
					$resvar = $rvalue;
					// }}}
				}
				else if (isset($this->unaryOp[$opc])) { // {{{
					$this->usedOps[$opc] = true;
					$op1val = $this->getOpVal($op1, $EX);
					$resvar = new Decompiler_UnaryOp($this, $opc, $op1val);
					// }}}
				}
				else {
					$notHandled = true;
				}
				// }}}
			}
			if ($notHandled) {
				fprintf(STDERR, "\x1B[31m%s\x1B[0m",  " * TODO " . $opname . PHP_EOL);
				echo $EX['indent'], "// TODO: ", $opname, PHP_EOL;
			}
			else {
				$this->usedOps[$opc] = true;
			}

			if (isset($resvar)) {
				if ($istmpres) {
					$T[$res['var']] = $resvar;
					$lastresvar = &$T[$res['var']];
				}
				else {
					$op['php'] = $resvar;
					$lastphpop = &$op;
					$lastresvar = &$op['php'];
				}
			}
		}
		return $T;
	}
	// }}}
	function unquote($str, $st, $ed) // {{{
	{
		$l1 = strlen($st);
		$l2 = strlen($ed);
		if (substr($str, 0, $l1) === $st && substr($str, -$l2) === $ed) {
			$str = substr($str, $l1, -$l2);
		}
		return $str;
	}
	// }}}
	function popargs(&$EX, $n) // {{{
	{
		$args = array();
		for ($i = 0; $i < $n; $i++) {
			$a = array_pop($EX['argstack']);
			if (is_array($a)) {
				array_unshift($args, foldToCode($a, $EX));
			}
			else {
				array_unshift($args, $a);
			}
		}
		return implode(', ', $args);
	}
	// }}}
	function opToString($op, $which, &$EX) // {{{
	{
		switch ($op['op_type']) {
		case XC_IS_UNUSED:
			return '?' . $op['opline_num'];

		case XC_IS_VAR:
			$s = '$' . $op['var'];
			if ($which != 'result' && isset($EX['Ts'])) {
				$s .= ':' . str($this->getOpVal($op, $EX, null));
			}
			return $s;

		case XC_IS_TMP_VAR:
			$s = '#' . $op['var'];
			if ($which != 'result' && isset($EX['Ts'])) {
				$s .= ':' . str($this->getOpVal($op, $EX, null));
			}
			return $s;

		case XC_IS_CONST:
			return isset($EX['Ts']) ? str($this->getOpVal($op, $EX, null)) : $op['var'] . ':' . var_export($op['constant'], true);

		default:
			return isset($EX['Ts']) ? str($this->getOpVal($op, $EX, null)) : $op['op_type'] . '?' . $op['var'];
		}
	}
	// }}}
	function dumpOp($op, &$EX) // {{{
	{
		assert('isset($op)');
		echo str_pad($op['line'], 4);
		echo str_pad($op['lineno'], 4);

		if (isset($op['oldopcode'])) {
			$name = '//' . xcache_get_opcode($op['oldopcode']);
		}
		else {
			$name = xcache_get_opcode($op['opcode']);
		}

		if (substr($name, 0, 5) == 'ZEND_') {
			$name = substr($name, 5);
		}
		echo ' ', str_pad($name, 25);

		$types = array('result' => 9, 'op1' => 20, 'op2' => 20);
		$res = $op['result'];
		$resUsed = ((ZEND_ENGINE_2_4 ? ($res['op_type'] & EXT_TYPE_UNUSED) : ($res['EA.type'] & EXT_TYPE_UNUSED)) || $res['op_type'] == XC_IS_UNUSED) ? '' : '=';
		foreach ($types as $which => $len) {
			echo ' ', str_pad($this->opToString($op[$which], $which, $EX) . ($which == 'result' ? $resUsed : ''), $len);
		}
		echo "\t;", $op['extended_value'];
		if (isset($op['isCatchBegin'])) {
			echo ' CB';
		}
		if (!empty($op['jmptos'])) {
			echo "\t>>", implode(',', $op['jmptos']);
		}
		if (!empty($op['jmpfroms'])) {
			echo "\t<<", implode(',', $op['jmpfroms']);
		}

		echo PHP_EOL;
	}
	// }}}
	function dumpRange($range, $ts = true) // {{{
	{
		;
		$EX = &$range['EX'];
		if (!$this->inComment++) {
			echo $EX['indent'], "/*", PHP_EOL;
		}
		if (!$ts) {
			$Ts = $EX['Ts'];
			$EX['Ts'] = null;
		}
		for ($i = $range[0]; $i <= $range[1]; ++$i) {
			echo $EX['indent'];
			$this->dumpOp($EX['opcodes'][$i], $EX);
		}
		if (!$ts) {
			$EX['Ts'] = $Ts;
		}
		if (!--$this->inComment) {
			echo $EX['indent'], "*/", PHP_EOL;
		}
	}
	// }}}
	function dargs(&$EX) // {{{
	{
		$op_array = &$EX['op_array'];

		if (isset($op_array['num_args'])) {
			$c = $op_array['num_args'];
		}
		else if (!empty($op_array['arg_types'])) {
			$c = count($op_array['arg_types']);
		}
		else {
			// php4
			$c = count($EX['recvs']);
		}

		$refrest = false;
		for ($i = 0; $i < $c; $i++) {
			if ($i) {
				echo ', ';
			}
			$arg = $EX['recvs'][$i + 1];
			if (isset($op_array['arg_info'])) {
				$ai = $op_array['arg_info'][$i];
				if (isset($ai['type_hint']) ? ($ai['type_hint'] == IS_CALLABLE || $ai['type_hint'] == IS_OBJECT) : !empty($ai['class_name'])) {
					echo $this->stripNamespace($ai['class_name']), ' ';
					if (!ZEND_ENGINE_2_2 && $ai['allow_null']) {
						echo 'or NULL ';
					}
				}
				else if (isset($ai['type_hint']) ? $ai['type_hint'] == IS_ARRAY : !empty($ai['array_type_hint'])) {
					echo 'array ';
					if (!ZEND_ENGINE_2_2 && $ai['allow_null']) {
						echo 'or NULL ';
					}
				}
				if ($ai['pass_by_reference']) {
					echo '&';
				}
				printf("\$%s", $ai['name']);
			}
			else {
				if ($refrest) {
					echo '&';
				}
				else if (!empty($op_array['arg_types']) && isset($op_array['arg_types'][$i])) {
					switch ($op_array['arg_types'][$i]) {
					case BYREF_FORCE_REST:
						$refrest = true;
						/* fall */
					case BYREF_FORCE:
						echo '&';
						break;

					case BYREF_NONE:
					case BYREF_ALLOW:
						break;
					default:
						assert(0);
					}
				}
				echo str($arg[0], $EX);
			}
			if (isset($arg[1])) {
				echo ' = ', str($arg[1], $EX);
			}
		}
	}
	// }}}
	function duses(&$EX) // {{{
	{
		if ($EX['uses']) {
			echo " use(", implode(', ', $EX['uses']), ')';
		}
	}
	// }}}
	function dfunction($func, $indent = '', $decorations = array(), $nobody = false) // {{{
	{
		$this->detectNamespace($func['op_array']['function_name']);

		$returnByRef = '';
		if ($nobody) {
			$EX = array();
			$EX['op_array'] = &$func['op_array'];
			$EX['recvs'] = array();
			$EX['uses'] = array();
		}
		else {
			ob_start();
			$EX = &$this->dop_array($func['op_array'], $indent . INDENT);
			$body = ob_get_clean();
			$hasReturn = false;
			$hasReturnByRef = false;
			foreach ($func['op_array']['opcodes'] as $op) {
				switch ($op['opcode']) {
				case XC_RETURN:
					$hasReturn = true;
					break;

				case XC_RETURN_BY_REF:
					$hasReturnByRef = true;
					break;
				}
			}
			if ($hasReturn && $hasReturnByRef) {
				echo $indent, "// WARN: both return and return-by-ref present", PHP_EOL;
			}
			if ($hasReturnByRef) {
				$returnByRef = '&';
			}
		}

		$functionName = $this->stripNamespace($func['op_array']['function_name']);
		$isExpression = false;
		if ($functionName == '{closure}') {
			$functionName = '';
			$isExpression = true;
		}
		echo $isExpression ? '' : $indent;
		if ($decorations) {
			echo implode(' ', $decorations), ' ';
		}
		echo 'function', $functionName ? ' ' . $returnByRef . $functionName : '', '(';
		$this->dargs($EX);
		echo ")";
		$this->duses($EX);
		if ($nobody) {
			echo ";", PHP_EOL;
		}
		else {
			if (!$isExpression) {
				echo PHP_EOL;
				echo $indent, "{", PHP_EOL;
			}
			else {
				echo " {", PHP_EOL;
			}

			echo $body;
			echo "$indent}";
			if (!$isExpression) {
				echo PHP_EOL;
			}
		}
	}
	// }}}
	function dclass($class, $indent = '') // {{{
	{
		$this->value2constant[$this->activeClass] = '__CLASS__';
		$this->detectNamespace($class['name']);

		// {{{ class decl
		if (!empty($class['doc_comment'])) {
			echo $indent;
			echo $class['doc_comment'];
			echo PHP_EOL;
		}
		$isInterface = false;
		$decorations = array();
		if (!empty($class['ce_flags'])) {
			if ($class['ce_flags'] & ZEND_ACC_INTERFACE) {
				$isInterface = true;
			}
			else {
				if ($class['ce_flags'] & ZEND_ACC_IMPLICIT_ABSTRACT_CLASS) {
					$decorations[] = "abstract";
				}
				if ($class['ce_flags'] & ZEND_ACC_FINAL_CLASS) {
					$decorations[] = "final";
				}
			}
		}

		echo $indent;
		if ($decorations) {
			echo implode(' ', $decorations), ' ';
		}
		echo $isInterface ? 'interface ' : 'class ', $this->stripNamespace($class['name']);
		if ($class['parent']) {
			echo ' extends ', $this->stripNamespace($class['parent']);
		}
		/* TODO */
		if (!empty($class['interfaces'])) {
			echo ' implements ';
			echo implode(', ', $class['interfaces']);
		}
		echo PHP_EOL;
		echo $indent, "{";
		// }}}
		$newindent = INDENT . $indent;
		// {{{ const
		if (!empty($class['constants_table'])) {
			echo PHP_EOL;
			foreach ($class['constants_table'] as $name => $v) {
				echo $newindent;
				echo 'const ', $name, ' = ';
				echo str(value($v, $EX), $newindent);
				echo ";", PHP_EOL;
			}
		}
		// }}}
		// {{{ properties
		if (ZEND_ENGINE_2 && !ZEND_ENGINE_2_4) {
			$default_static_members = $class[ZEND_ENGINE_2_1 ? 'default_static_members' : 'static_members'];
		}
		$member_variables = $class[ZEND_ENGINE_2 ? 'properties_info' : 'default_properties'];
		if ($member_variables) {
			echo PHP_EOL;
			foreach ($member_variables as $name => $dummy) {
				$info = isset($class['properties_info']) ? $class['properties_info'][$name] : null;
				if (isset($info) && !empty($info['doc_comment'])) {
					echo $newindent;
					echo $info['doc_comment'];
					echo PHP_EOL;
				}

				echo $newindent;
				if (ZEND_ENGINE_2) {
					$static = ($info['flags'] & ZEND_ACC_STATIC);

					if ($static) {
						echo "static ";
					}
				}

				$mangleSuffix = '';
				if (!ZEND_ENGINE_2) {
					echo 'var ';
				}
				else if (!isset($info)) {
					echo 'public ';
				}
				else {
					if ($info['flags'] & ZEND_ACC_SHADOW) {
						continue;
					}
					switch ($info['flags'] & ZEND_ACC_PPP_MASK) {
					case ZEND_ACC_PUBLIC:
						echo "public ";
						break;
					case ZEND_ACC_PRIVATE:
						echo "private ";
						$mangleSuffix = "\000";
						break;
					case ZEND_ACC_PROTECTED:
						echo "protected ";
						$mangleSuffix = "\000";
						break;
					}
				}

				echo '$', $name;

				if (ZEND_ENGINE_2_4) {
					$value = $class[$static ? 'default_static_members_table' : 'default_properties_table'][$info['offset']];
				}
				else if (!ZEND_ENGINE_2) {
					$value = $class['default_properties'][$name];
				}
				else {
					$key = $info['name'] . $mangleSuffix;
					if ($static) {
						$value = $default_static_members[$key];
					}
					else {
						$value = $class['default_properties'][$key];
					}
				}
				if (isset($value)) {
					echo ' = ';
					echo str(value($value, $EX), $newindent);
				}
				echo ";", PHP_EOL;
			}
		}
		// }}}
		// {{{ function_table
		if (isset($class['function_table'])) {
			foreach ($class['function_table'] as $func) {
				if (!isset($func['scope']) || $func['scope'] == $class['name']) {
					// TODO: skip shadow here
					echo PHP_EOL;
					$opa = $func['op_array'];
					if (!empty($opa['doc_comment'])) {
						echo $newindent;
						echo $opa['doc_comment'];
						echo PHP_EOL;
					}
					$isAbstractMethod = false;
					$decorations = array();
					if (isset($opa['fn_flags'])) {
						if (($opa['fn_flags'] & ZEND_ACC_ABSTRACT) && !$isInterface) {
							$decorations[] = "abstract";
							$isAbstractMethod = true;
						}
						if ($opa['fn_flags'] & ZEND_ACC_FINAL) {
							$decorations[] = "final";
						}
						if ($opa['fn_flags'] & ZEND_ACC_STATIC) {
							$decorations[] = "static";
						}

						switch ($opa['fn_flags'] & ZEND_ACC_PPP_MASK) {
						case ZEND_ACC_PUBLIC:
							$decorations[] = "public";
							break;
						case ZEND_ACC_PRIVATE:
							$decorations[] = "private";
							break;
						case ZEND_ACC_PROTECTED:
							$decorations[] = "protected";
							break;
						default:
							$decorations[] = "<visibility error>";
							break;
						}
					}
					$this->activeMethod = $this->activeClass . '::' . $opa['function_name'];
					$this->activeFunction = $opa['function_name'];
					$this->dfunction($func, $newindent, $decorations, $isInterface || $isAbstractMethod);
					$this->activeFunction = null;
					$this->activeMethod = null;
					if ($opa['function_name'] == 'Decompiler') {
						//exit;
					}
				}
			}
		}
		// }}}
		echo $indent, "}", PHP_EOL;
		unset($this->value2constant[$this->activeClass]);
	}
	// }}}
	function decompileString($string) // {{{
	{
		$this->dc = xcache_dasm_string($string);
		if ($this->dc === false) {
			echo "error compling string", PHP_EOL;
			return false;
		}
		$this->activeFile = null;
		$this->activeDir = null;
		return true;
	}
	// }}}
	function decompileFile($file) // {{{
	{
		$this->dc = xcache_dasm_file($file);
		if ($this->dc === false) {
			echo "error compling $file", PHP_EOL;
			return false;
		}
		$this->activeFile = realpath($file);
		if (ZEND_ENGINE_2_3) {
			$this->activeDir = dirname($this->activeFile);
		}
		$this->value2constant[$this->activeFile] = '__FILE__';
		$this->value2constant[$this->activeDir] = '__DIR__';
		return true;
	}
	// }}}
	function decompileDasm($content) // {{{
	{
		$this->dc = $content;
		$this->activeFile = null;
		$this->activeDir = null;
		return true;
	}
	// }}}
	function output() // {{{
	{
		echo "<?". "php";
		echo PHP_EOL, PHP_EOL;
		foreach ($this->dc['class_table'] as $key => $class) {
			if ($key{0} != "\0") {
				$this->activeClass = $class['name'];
				$this->dclass($class);
				$this->activeClass = null;
				echo PHP_EOL;
			}
		}

		foreach ($this->dc['function_table'] as $key => $func) {
			if ($key{0} != "\0") {
				$this->activeFunction = $key;
				$this->dfunction($func);
				$this->activeFunction = null;
				echo PHP_EOL;
			}
		}

		$this->dop_array($this->dc['op_array']);
		echo PHP_EOL, "?" . ">", PHP_EOL;

		if (!empty($this->test)) {
			$this->outputUnusedOp();
		}
		return true;
	}
	// }}}
	function outputUnusedOp() // {{{
	{
		for ($i = 0; $opname = xcache_get_opcode($i); $i++) {
			if ($opname == 'UNDEF') {
				continue;
			}

			if (!isset($this->usedOps[$i])) {
				echo "not covered opcode ", $opname, PHP_EOL;
			}
		}
	}
	// }}}
}

// {{{ defines
define('ZEND_ENGINE_2_6', PHP_VERSION >= "5.6");
define('ZEND_ENGINE_2_5', ZEND_ENGINE_2_6 || PHP_VERSION >= "5.5.");
define('ZEND_ENGINE_2_4', ZEND_ENGINE_2_5 || PHP_VERSION >= "5.4.");
define('ZEND_ENGINE_2_3', ZEND_ENGINE_2_4 || PHP_VERSION >= "5.3.");
define('ZEND_ENGINE_2_2', ZEND_ENGINE_2_3 || PHP_VERSION >= "5.2.");
define('ZEND_ENGINE_2_1', ZEND_ENGINE_2_2 || PHP_VERSION >= "5.1.");
define('ZEND_ENGINE_2',   ZEND_ENGINE_2_1 || PHP_VERSION >= "5.0.");

define('ZEND_ACC_STATIC',         0x01);
define('ZEND_ACC_ABSTRACT',       0x02);
define('ZEND_ACC_FINAL',          0x04);
define('ZEND_ACC_IMPLEMENTED_ABSTRACT',       0x08);

define('ZEND_ACC_IMPLICIT_ABSTRACT_CLASS',    0x10);
define('ZEND_ACC_EXPLICIT_ABSTRACT_CLASS',    0x20);
define('ZEND_ACC_FINAL_CLASS',                0x40);
define('ZEND_ACC_INTERFACE',                  0x80);
if (ZEND_ENGINE_2_4) {
	define('ZEND_ACC_TRAIT',                  0x120);
}
define('ZEND_ACC_PUBLIC',     0x100);
define('ZEND_ACC_PROTECTED',  0x200);
define('ZEND_ACC_PRIVATE',    0x400);
define('ZEND_ACC_PPP_MASK',  (ZEND_ACC_PUBLIC | ZEND_ACC_PROTECTED | ZEND_ACC_PRIVATE));

define('ZEND_ACC_CHANGED',    0x800);
define('ZEND_ACC_IMPLICIT_PUBLIC',    0x1000);

define('ZEND_ACC_CTOR',       0x2000);
define('ZEND_ACC_DTOR',       0x4000);
define('ZEND_ACC_CLONE',      0x8000);

define('ZEND_ACC_ALLOW_STATIC',   0x10000);

define('ZEND_ACC_SHADOW', 0x2000);

if (ZEND_ENGINE_2_4) {
	define('ZEND_FETCH_GLOBAL',           0x00000000);
	define('ZEND_FETCH_LOCAL',            0x10000000);
	define('ZEND_FETCH_STATIC',           0x20000000);
	define('ZEND_FETCH_STATIC_MEMBER',    0x30000000);
	define('ZEND_FETCH_GLOBAL_LOCK',      0x40000000);
	define('ZEND_FETCH_LEXICAL',          0x50000000);

	define('ZEND_FETCH_TYPE_MASK',        0x70000000);

	define('ZEND_FETCH_STANDARD',         0x00000000);
	define('ZEND_FETCH_ADD_LOCK',         0x08000000);
	define('ZEND_FETCH_MAKE_REF',         0x04000000);
}
else {
	define('ZEND_FETCH_GLOBAL',           0);
	define('ZEND_FETCH_LOCAL',            1);
	define('ZEND_FETCH_STATIC',           2);
	define('ZEND_FETCH_STATIC_MEMBER',    3);
	define('ZEND_FETCH_GLOBAL_LOCK',      4);

	define('ZEND_FETCH_STANDARD',         0);
	define('ZEND_FETCH_ADD_LOCK',         1);
}

if (ZEND_ENGINE_2_4) {
	define('ZEND_ISSET',                  0x02000000);
	define('ZEND_ISEMPTY',                0x01000000);
	define('ZEND_ISSET_ISEMPTY_MASK',     (ZEND_ISSET | ZEND_ISEMPTY));
	define('ZEND_QUICK_SET',              0x00800000);
}
else {
	define('ZEND_ISSET',                  (1<<0));
	define('ZEND_ISEMPTY',                (1<<1));

	define('ZEND_ISSET_ISEMPTY_MASK',     (ZEND_ISSET | ZEND_ISEMPTY));
}

define('ZEND_FETCH_CLASS_DEFAULT',    0);
define('ZEND_FETCH_CLASS_SELF',       1);
define('ZEND_FETCH_CLASS_PARENT',     2);
define('ZEND_FETCH_CLASS_MAIN',       3);
define('ZEND_FETCH_CLASS_GLOBAL',     4);
define('ZEND_FETCH_CLASS_AUTO',       5);
define('ZEND_FETCH_CLASS_INTERFACE',  6);
define('ZEND_FETCH_CLASS_STATIC',     7);
if (ZEND_ENGINE_2_4) {
	define('ZEND_FETCH_CLASS_TRAIT',     14);
}
if (ZEND_ENGINE_2_3) {
	define('ZEND_FETCH_CLASS_MASK',     0xF);
}

define('ZEND_EVAL',               (1<<0));
define('ZEND_INCLUDE',            (1<<1));
define('ZEND_INCLUDE_ONCE',       (1<<2));
define('ZEND_REQUIRE',            (1<<3));
define('ZEND_REQUIRE_ONCE',       (1<<4));

if (ZEND_ENGINE_2_4) {
	define('EXT_TYPE_UNUSED',     (1<<5));
}
else {
	define('EXT_TYPE_UNUSED',     (1<<0));
}

if (ZEND_ENGINE_2_1) {
	define('ZEND_FE_FETCH_BYREF',     1);
	define('ZEND_FE_FETCH_WITH_KEY',  2);
}
else {
	define('ZEND_UNSET_DIM',          1);
	define('ZEND_UNSET_OBJ',          2);
}

define('ZEND_MEMBER_FUNC_CALL',   1<<0);
define('ZEND_CTOR_CALL',          1<<1);

define('ZEND_ARG_SEND_BY_REF',        (1<<0));
define('ZEND_ARG_COMPILE_TIME_BOUND', (1<<1));
define('ZEND_ARG_SEND_FUNCTION',      (1<<2));

define('BYREF_NONE',       0);
define('BYREF_FORCE',      1);
define('BYREF_ALLOW',      2);
define('BYREF_FORCE_REST', 3);
define('IS_NULL',     0);
define('IS_LONG',     1);
define('IS_DOUBLE',   2);
define('IS_BOOL',     ZEND_ENGINE_2_1 ? 3 : 6);
define('IS_ARRAY',    4);
define('IS_OBJECT',   5);
define('IS_STRING',   ZEND_ENGINE_2_1 ? 6 : 3);
define('IS_RESOURCE', 7);
define('IS_CONSTANT', 8);
if (ZEND_ENGINE_2_6) {
	define('IS_CONSTANT_ARRAY', -1);
	define('IS_CONSTANT_AST', 9);
}
else {
	define('IS_CONSTANT_ARRAY', 9);
}
if (ZEND_ENGINE_2_4) {
	define('IS_CALLABLE', 10);
}
/* Ugly hack to support constants as static array indices */
define('IS_CONSTANT_TYPE_MASK',   0x0f);
define('IS_CONSTANT_UNQUALIFIED', 0x10);
define('IS_CONSTANT_INDEX',       0x80);
define('IS_LEXICAL_VAR',          0x20);
define('IS_LEXICAL_REF',          0x40);

if (ZEND_ENGINE_2_6) {
	define('ZEND_CONST',          256);
	define('ZEND_BOOL_AND',       256 + 1);
	define('ZEND_BOOL_OR',        256 + 2);
	define('ZEND_SELECT',         256 + 3);
	define('ZEND_UNARY_PLUS',     256 + 4);
	define('ZEND_UNARY_MINUS',    256 + 5);
}

@define('XC_IS_CV', 16);
if (!defined("PHP_EOL")) {
	define("PHP_EOL", "\n");
}

/*
if (preg_match_all('!XC_[A-Z_]+!', file_get_contents(__FILE__), $ms)) {
	$verdiff = array();
	foreach ($ms[0] as $k) {
		if (!defined($k)) {
			$verdiff[$k] = -1;
			define($k, -1);
		}
	}
	var_export($verdiff);
	exit;
}
//*/
foreach (array(
	'XC_ADD_INTERFACE' => -1,
	'XC_ASSIGN_DIM' => -1,
	'XC_ASSIGN_OBJ' => -1,
	'XC_ASSIGN_POW' => -1,
	'XC_CATCH' => -1,
	'XC_CLONE' => -1,
	'XC_DECLARE_CLASS' => -1,
	'XC_DECLARE_CONST' => -1,
	'XC_DECLARE_FUNCTION' => -1,
	'XC_DECLARE_FUNCTION_OR_CLASS' => -1,
	'XC_DECLARE_INHERITED_CLASS' => -1,
	'XC_DECLARE_INHERITED_CLASS_DELAYED' => -1,
	'XC_DECLARE_LAMBDA_FUNCTION' => -1,
	'XC_DO_FCALL_BY_FUNC' => -1,
	'XC_FETCH_CLASS' => -1,
	'XC_GENERATOR_RETURN' => -1,
	'XC_GOTO' => -1,
	'XC_HANDLE_EXCEPTION' => -1,
	'XC_INIT_CTOR_CALL' => -1,
	'XC_INIT_FCALL_BY_FUNC' => -1,
	'XC_INIT_METHOD_CALL' => -1,
	'XC_INIT_NS_FCALL_BY_NAME' => -1,
	'XC_INIT_STATIC_METHOD_CALL' => -1,
	'XC_INSTANCEOF' => -1,
	'XC_ISSET_ISEMPTY' => -1,
	'XC_ISSET_ISEMPTY_DIM_OBJ' => -1,
	'XC_ISSET_ISEMPTY_PROP_OBJ' => -1,
	'XC_ISSET_ISEMPTY_VAR' => -1,
	'XC_JMP_NO_CTOR' => -1,
	'XC_JMP_SET' => -1,
	'XC_JMP_SET_VAR' => -1,
	'XC_OP_DATA' => -1,
	'XC_POST_DEC_OBJ' => -1,
	'XC_POST_INC_OBJ' => -1,
	'XC_POW' => -1,
	'XC_PRE_DEC_OBJ' => -1,
	'XC_PRE_INC_OBJ' => -1,
	'XC_QM_ASSIGN_VAR' => -1,
	'XC_RAISE_ABSTRACT_ERROR' => -1,
	'XC_RETURN_BY_REF' => -1,
	'XC_THROW' => -1,
	'XC_UNSET_DIM' => -1,
	'XC_UNSET_DIM_OBJ' => -1,
	'XC_UNSET_OBJ' => -1,
	'XC_USER_OPCODE' => -1,
	'XC_VERIFY_ABSTRACT_CLASS' => -1,
	'XC_YIELD' => -1,
) as $k => $v) {
	if (!defined($k)) {
		define($k, $v);
	}
}
// }}}

