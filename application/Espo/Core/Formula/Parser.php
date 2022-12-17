<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Formula;

use Espo\Core\Formula\Exceptions\SyntaxError;
use Espo\Core\Formula\Parser\Statement\IfRef;
use Espo\Core\Formula\Parser\Statement\StatementRef;

use LogicException;
use stdClass;

/**
 * Parses a formula script into AST. Returns a RAW data object that represents a tree of functions.
 */
class Parser
{
    /** @var array<int, string[]> */
    private $priorityList = [
        ['='],
        ['??'],
        ['||'],
        ['&&'],
        ['==', '!=', '>', '<', '>=', '<='],
        ['+', '-'],
        ['*', '/', '%'],
    ];

    /** @var array<string, string> */
    private $operatorMap = [
        '=' => 'assign',
        '??' => 'comparison\\nullCoalescing',
        '||' => 'logical\\or',
        '&&' => 'logical\\and',
        '+' => 'numeric\\summation',
        '-' => 'numeric\\subtraction',
        '*' => 'numeric\\multiplication',
        '/' => 'numeric\\division',
        '%' => 'numeric\\modulo',
        '==' => 'comparison\\equals',
        '!=' => 'comparison\\notEquals',
        '>' => 'comparison\\greaterThan',
        '<' => 'comparison\\lessThan',
        '>=' => 'comparison\\greaterThanOrEquals',
        '<=' => 'comparison\\lessThanOrEquals',
    ];

    /** @var string[] */
    private array $whiteSpaceCharList = [
        "\r",
        "\n",
        "\t",
        ' ',
    ];

    private string $variableNameRegExp = "/^[a-zA-Z0-9_\$]+$/";
    private string $functionNameRegExp = "/^[a-zA-Z0-9_\\\\]+$/";
    private string $attributeNameRegExp = "/^[a-zA-Z0-9.]+$/";

    /**
     * @throws SyntaxError
     */
    public function parse(string $expression): stdClass
    {
        return $this->split($expression);
    }

    /**
     * @throws SyntaxError
     */
    private function applyOperator(string $operator, string $firstPart, string $secondPart): stdClass
    {
        if ($operator === '=') {
            if (!strlen($firstPart)) {
                throw new SyntaxError("Bad operator usage.");
            }

            if ($firstPart[0] == '$') {
                $variable = substr($firstPart, 1);

                if ($variable === '' || !preg_match($this->variableNameRegExp, $variable)) {
                    throw new SyntaxError("Bad variable name `{$variable}`.");
                }

                return (object) [
                    'type' => 'assign',
                    'value' => [
                        (object) [
                            'type' => 'value',
                            'value' => $variable,
                        ],
                        $this->split($secondPart),
                    ]
                ];
            }

            if ($secondPart === '') {
                throw SyntaxError::create("Bad assignment usage.");
            }

            return (object) [
                'type' => 'setAttribute',
                'value' => [
                    (object) [
                        'type' => 'value',
                        'value' => $firstPart,
                    ],
                    $this->split($secondPart)
                ]
            ];
        }

        $functionName = $this->operatorMap[$operator];

        if ($functionName === '' || !preg_match($this->functionNameRegExp, $functionName)) {
            throw new SyntaxError("Bad function name `{$functionName}`.");
        }

        return (object) [
            'type' => $functionName,
            'value' => [
                $this->split($firstPart),
                $this->split($secondPart),
            ]
        ];
    }

    /**
     * @param ?((StatementRef|IfRef)[]) $statementList
     * @throws SyntaxError
     */
    private function processStrings(
        string &$string,
        string &$modifiedString,
        ?array &$statementList = null,
        bool $intoOneLine = false
    ): bool {

        $isString = false;
        $isSingleQuote = false;
        $isComment = false;
        $isLineComment = false;
        $parenthesisCounter = 0;
        $braceCounter = 0;

        $modifiedString = $string;

        for ($i = 0; $i < strlen($string); $i++) {
            $isStringStart = false;
            $char = $string[$i];
            $isLast = $i === strlen($string) - 1;

            if (!$isLineComment && !$isComment) {
                if ($string[$i] === "'" && ($i === 0 || $string[$i - 1] !== "\\")) {
                    if (!$isString) {
                        $isString = true;
                        $isSingleQuote = true;
                        $isStringStart = true;
                    }
                    else if ($isSingleQuote) {
                        $isString = false;
                    }
                }
                else if ($string[$i] === "\"" && ($i === 0 || $string[$i - 1] !== "\\")) {
                    if (!$isString) {
                        $isString = true;
                        $isStringStart = true;
                        $isSingleQuote = false;
                    }
                    else if (!$isSingleQuote) {
                        $isString = false;
                    }
                }
            }

            if ($isString) {
                if (in_array($char, ['(', ')', '{', '}'])) {
                    $modifiedString[$i] = '_';
                }
                else if (!$isStringStart) {
                    $modifiedString[$i] = ' ';
                }

                continue;
            }

            if (!$isLineComment && !$isComment) {
                if ($i && $string[$i] === '/' && $string[$i - 1] === '/') {
                    $isLineComment = true;
                }

                if (!$isLineComment) {
                    if ($i && $string[$i] === '*' && $string[$i - 1] === '/') {
                        $isComment = true;
                    }
                }

                $lastStatement = $statementList !== null && count($statementList) ?
                    end($statementList) : null;

                if ($char === '(') {
                    $parenthesisCounter++;
                }

                if ($char === ')') {
                    $parenthesisCounter--;
                }

                if ($char === '{') {
                    $braceCounter++;
                }

                if ($char === '}') {
                    $braceCounter--;
                }

                if (
                    $lastStatement instanceof IfRef &&
                    !$lastStatement->isReady()
                ) {
                    $toContinue = $this->processStringIfStatement(
                        $string,
                        $i,
                        $parenthesisCounter,
                        $braceCounter,
                        $lastStatement
                    );

                    if ($toContinue) {
                        continue;
                    }
                }

                if (
                    $parenthesisCounter === 0 &&
                    $braceCounter === 0 &&
                    $statementList !== null
                ) {
                    $previousStatementEnd = $lastStatement ?
                        $lastStatement->getEnd() :
                        -1;

                    if (
                        $lastStatement instanceof IfRef &&
                        !$lastStatement->isReady()
                    ) {
                        continue;
                    }

                    if ($previousStatementEnd === null) {
                        throw SyntaxError::create("Incorrect statement usage.");
                    }

                    if ($char === ';') {
                        $statementList[] = new StatementRef($previousStatementEnd + 1, $i);
                    }
                    else if ($isLast && count($statementList)) {
                        $statementList[] = new StatementRef($previousStatementEnd + 1, $i + 1);
                    }
                    else if ($this->isOnIf($string, $i - 1)) {
                        $statementList[] = new IfRef();
                    }
                }

                if ($intoOneLine) {
                    if ($parenthesisCounter === 0) {
                        if ($this->isWhiteSpace($char) && $char !== ' ') {
                            $string[$i] = ' ';
                        }
                    }
                }
            }

            if ($isLineComment) {
                if ($string[$i] === "\n") {
                    $isLineComment = false;
                }
            }

            if ($isComment) {
                if ($string[$i - 1] === "*" && $string[$i] === "/") {
                    $isComment = false;
                }
            }
        }

        return $isString;
    }

    private function processStringIfStatement(
        string $string,
        int &$i,
        int $parenthesisCounter,
        int $braceCounter,
        IfRef $statement
    ): bool {

        $char = $string[$i];
        $isLast = $i === strlen($string) - 1;

        if (
            $char === '(' &&
            !$isLast &&
            $parenthesisCounter === 1 &&
            $braceCounter === 0 &&
            $statement->getState() === IfRef::STATE_EMPTY
        ) {
            $statement->setConditionStart($i + 1);

            return true;
        }

        if (
            $char === ')' &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $statement->getState() === IfRef::STATE_CONDITION_STARTED
        ) {
            $statement->setConditionEnd($i);

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_CONDITION_ENDED &&
            !$isLast &&
            $parenthesisCounter === 0 &&
            $braceCounter === 1 &&
            $char === '{'
        ) {
            $statement->setThenStart($i + 1);

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_THEN_STARTED &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $char === '}'
        ) {
            $statement->setThenEnd($i);

            if ($isLast) {
                $statement->setReady();
            }

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_THEN_ENDED &&
            !$this->isWhiteSpace($char) &&
            !$this->isOnElse($string, $i)
        ) {
            $statement->setReady();

            // No need to call continue.
            return false;
        }

        if (
            $statement->getState() === IfRef::STATE_THEN_ENDED &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $this->isOnElse($string, $i)
        ) {
            $statement->setElseMet($i + 4);

            $i += 3;

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_ELSE_MET &&
            !$isLast &&
            $parenthesisCounter === 0 &&
            $braceCounter === 1 &&
            $char === '{'
        ) {
            $statement->setElseStart($i + 1);

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_ELSE_MET &&
            !$isLast &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $this->isWhiteSpace($string[$i - 1]) &&
            $this->isOnIf($string, $i)
        ) {
            $statement->setElseStart($i, true);

            $i += 1;

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_ELSE_STARTED &&
            $statement->hasInlineElse() &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $char === '}'
        ) {
            $elseFound = false;
            $j = $i + 1;

            while ($j < strlen($string)) {
                if ($this->isWhiteSpace($string[$j])) {
                    $j++;

                    continue;
                }

                $elseFound = $this->isOnElse($string, $j);

                break;
            }

            if (!$elseFound) {
                $statement->setElseEnd($i + 1);
                $statement->setReady();
            }

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_ELSE_STARTED &&
            !$statement->hasInlineElse() &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $char === '}'
        ) {
            $statement->setElseEnd($i);
            $statement->setReady();

            return true;
        }

        if (
            $statement->getState() === IfRef::STATE_ELSE_MET &&
            $parenthesisCounter === 0 &&
            $braceCounter === 0 &&
            $char === '}'
        ) {
            $statement->setElseStart($statement->getElseKeywordEnd() + 1);
            $statement->setElseEnd($i + 1);
            $statement->setReady();

            return true;
        }

        return false;
    }

    private function isOnIf(string $string, int $i): bool
    {
        $before = substr($string, $i - 1, 1);
        $after = substr($string, $i + 2, 1);

        return
            substr($string, $i, 2) === 'if' &&
            (
                $i === 0 ||
                $this->isWhiteSpace($before) ||
                $before === ';'
            ) &&
            (
                $this->isWhiteSpace($after) ||
                $after === '('
            );
    }

    private function isOnElse(string $string, int $i): bool
    {
        return substr($string, $i, 4) === 'else' &&
            $this->isWhiteSpaceCharOrBraceOpen(substr($string, $i + 4, 1)) &&
            $this->isWhiteSpaceCharOrBraceClose(substr($string, $i - 1, 1));
    }

    private function isWhiteSpaceCharOrBraceOpen(string $char): bool
    {
        return $char === '{' || in_array($char, $this->whiteSpaceCharList);
    }

    private function isWhiteSpaceCharOrBraceClose(string $char): bool
    {
        return $char === '}' || in_array($char, $this->whiteSpaceCharList);
    }

    private function isWhiteSpace(string $char): bool
    {
        return in_array($char, $this->whiteSpaceCharList);
    }

    /**
     * @throws SyntaxError
     */
    private function split(string $expression): stdClass
    {
        $expression = trim($expression);

        $parenthesisCounter = 0;
        $braceCounter = 0;
        $hasExcessParenthesis = true;
        $modifiedExpression = '';
        $expressionOutOfParenthesisList = [];

        $statementList = [];

        $isStringNotClosed = $this->processStrings($expression, $modifiedExpression, $statementList, true);

        if ($isStringNotClosed) {
            throw SyntaxError::create('String is not closed.');
        }

        $this->stripComments($expression, $modifiedExpression);

        // @todo Revise.
        /*
        foreach ($splitterIndexList as $i => $index) {
            if ($expression[$index] !== ';') {
                unset($splitterIndexList[$i]);
            }
        }
        $splitterIndexList = array_values($splitterIndexList);
        */

        $expressionLength = strlen($modifiedExpression);

        for ($i = 0; $i < $expressionLength; $i++) {
            $value = $modifiedExpression[$i];

            if ($value === '(') {
                $parenthesisCounter++;
            }
            else if ($value === ')') {
                $parenthesisCounter--;
            }
            else if ($value === '{') {
                $braceCounter++;
            }
            else if ($value === '}') {
                $braceCounter--;
            }

            if ($parenthesisCounter === 0 && $i < $expressionLength - 1) {
                $hasExcessParenthesis = false;
            }

            $expressionOutOfParenthesisList[] = $parenthesisCounter === 0;
        }

        if ($parenthesisCounter !== 0) {
            throw SyntaxError::create(
                'Incorrect parentheses usage in expression ' . $expression . '.',
                'Incorrect parentheses.'
            );
        }

        if ($braceCounter !== 0) {
            throw SyntaxError::create(
                'Incorrect braces usage in expression ' . $expression . '.',
                'Incorrect braces.'
            );
        }

        if (
            strlen($expression) > 1 &&
            $expression[0] === '(' &&
            $expression[strlen($expression) - 1] === ')' &&
            $hasExcessParenthesis
        ) {
            $expression = substr($expression, 1, strlen($expression) - 2);

            return $this->split($expression);
        }

        if (count($statementList)) {
            return $this->processStatementList($expression, $statementList);
        }

        $firstOperator = null;
        $minIndex = null;

        if (trim($expression) === '') {
            return (object) [
                'type' => 'value',
                'value' => null,
            ];
        }

        foreach ($this->priorityList as $operationList) {
            foreach ($operationList as $operator) {
                $startFrom = 1;

                while (true) {
                    $index = strpos($expression, $operator, $startFrom);

                    if ($index === false) {
                        break;
                    }

                    if ($expressionOutOfParenthesisList[$index]) {
                        break;
                    }

                    $startFrom = $index + 1;
                }

                if ($index !== false) {
                    $possibleRightOperator = null;

                    if (strlen($operator) === 1) {
                        if ($index < strlen($expression) - 1) {
                            $possibleRightOperator = trim($operator . $expression[$index + 1]);
                        }
                    }

                    if (
                        $possibleRightOperator &&
                        $possibleRightOperator != $operator &&
                        !empty($this->operatorMap[$possibleRightOperator])
                    ) {
                        continue;
                    }

                    $possibleLeftOperator = null;

                    if (strlen($operator) === 1) {
                        if ($index > 0) {
                            $possibleLeftOperator = trim($expression[$index - 1] . $operator);
                        }
                    }

                    if (
                        $possibleLeftOperator &&
                        $possibleLeftOperator != $operator &&
                        !empty($this->operatorMap[$possibleLeftOperator])
                    ) {
                        continue;
                    }

                    $firstPart = substr($expression, 0, $index);
                    $secondPart = substr($expression, $index + strlen($operator));

                    $modifiedFirstPart = $modifiedSecondPart = '';

                    $isString = $this->processStrings($firstPart, $modifiedFirstPart);

                    $this->processStrings($secondPart, $modifiedSecondPart);

                    if (
                        substr_count($modifiedFirstPart, '(') === substr_count($modifiedFirstPart, ')') &&
                        substr_count($modifiedSecondPart, '(') === substr_count($modifiedSecondPart, ')') &&
                        !$isString
                    ) {
                        if ($minIndex === null) {
                            $minIndex = $index;

                            $firstOperator = $operator;
                        }
                        else if ($index < $minIndex) {
                            $minIndex = $index;

                            $firstOperator = $operator;
                        }
                    }
                }
            }

            if ($firstOperator) {
                break;
            }
        }

        if ($firstOperator) {
            /** @var int $minIndex */

            $firstPart = substr($expression, 0, $minIndex);
            $secondPart = substr($expression, $minIndex + strlen($firstOperator));

            $firstPart = trim($firstPart);
            $secondPart = trim($secondPart);

            return $this->applyOperator($firstOperator, $firstPart, $secondPart);
        }

        $expression = trim($expression);

        if ($expression[0] === '!') {
            return (object) [
                'type' => 'logical\\not',
                'value' => $this->split(substr($expression, 1))
            ];
        }

        if ($expression[0] === '-') {
            return (object) [
                'type' => 'numeric\\subtraction',
                'value' => [
                    $this->split('0'),
                    $this->split(substr($expression, 1))
                ]
            ];
        }

        if ($expression[0] === '+') {
            return (object) [
                'type' => 'numeric\\summation',
                'value' => [
                    $this->split('0'),
                    $this->split(substr($expression, 1))
                ]
            ];
        }

        if (
            $expression[0] === "'" && $expression[strlen($expression) - 1] === "'" ||
            $expression[0] === "\"" && $expression[strlen($expression) - 1] === "\""
        ) {
            return (object) [
                'type' => 'value',
                'value' => substr($expression, 1, strlen($expression) - 2)
            ];
        }

        if ($expression[0] === "$") {
            $value = substr($expression, 1);

            if ($value === '' || !preg_match($this->variableNameRegExp, $value)) {
                throw new SyntaxError("Bad variable name `{$value}`.");
            }

            return (object) [
                'type' => 'variable',
                'value' => $value,
            ];
        }

        if (is_numeric($expression)) {
            $value = filter_var($expression, FILTER_VALIDATE_INT) !== false ?
                (int) $expression :
                (float) $expression;

            return (object) [
                'type' => 'value',
                'value' => $value,
            ];
        }

        if ($expression === 'true') {
            return (object) [
                'type' => 'value',
                'value' => true,
            ];
        }

        if ($expression === 'false') {
            return (object) [
                'type' => 'value',
                'value' => false,
            ];
        }

        if ($expression === 'null') {
            return (object) [
                'type' => 'value',
                'value' => null,
            ];
        }

        if ($expression[strlen($expression) - 1] === ')') {
            $firstOpeningBraceIndex = strpos($expression, '(');

            if ($firstOpeningBraceIndex > 0) {
                $functionName = trim(substr($expression, 0, $firstOpeningBraceIndex));
                $functionContent = substr($expression, $firstOpeningBraceIndex + 1, -1);

                $argumentList = $this->parseArgumentListFromFunctionContent($functionContent);

                $argumentSplitList = [];

                foreach ($argumentList as $argument) {
                    $argumentSplitList[] = $this->split($argument);
                }

                if ($functionName === '' || !preg_match($this->functionNameRegExp, $functionName)) {
                    throw new SyntaxError("Bad function name `{$functionName}`.");
                }

                return (object) [
                    'type' => $functionName,
                    'value' => $argumentSplitList,
                ];
            }
        }

        if (!preg_match($this->attributeNameRegExp, $expression)) {
            throw SyntaxError::create("Attribute name `$expression` contains not allowed characters.");
        }

        if (str_ends_with($expression, '.')) {
            throw SyntaxError::create("Attribute ends with dot.");
        }

        return (object) [
            'type' => 'attribute',
            'value' => $expression,
        ];
    }

    /**
     * @param ?((StatementRef|IfRef)[]) $statementList
     * @throws SyntaxError
     */
    private function processStatementList(string $expression, array $statementList): stdClass
    {
        $parsedPartList = [];

        foreach ($statementList as $statement) {
            $parsedPart = null;

            if ($statement instanceof StatementRef) {
                $part = self::sliceByStartEnd($expression, $statement->getStart(), $statement->getEnd());

                $parsedPart = $this->parse($part);
            }
            else if ($statement instanceof IfRef) {
                if (!$statement->isReady()) {
                    throw SyntaxError::create(
                        'Incorrect if statement usage in expression ' . $expression . '.',
                        'Incorrect if statement.'
                    );
                }

                $conditionStart = $statement->getConditionStart();
                $conditionEnd = $statement->getConditionEnd();
                $thenStart = $statement->getThenStart();
                $thenEnd = $statement->getThenEnd();
                $elseStart = $statement->getElseStart();
                $elseEnd = $statement->getElseEnd();

                if (
                    $conditionStart === null ||
                    $conditionEnd === null ||
                    $thenStart === null ||
                    $thenEnd === null
                ) {
                    throw new LogicException();
                }

                $conditionPart = self::sliceByStartEnd($expression, $conditionStart, $conditionEnd);
                $thenPart = self::sliceByStartEnd($expression, $thenStart, $thenEnd);
                $elsePart = $elseStart !== null && $elseEnd !== null ?
                    self::sliceByStartEnd($expression, $elseStart, $elseEnd) : null;

                $parsedPart = $statement->getElseKeywordEnd() ?
                    (object) [
                        'type' => 'ifThenElse',
                        'value' => [
                            $this->parse($conditionPart),
                            $this->parse($thenPart),
                            $this->parse($elsePart)
                        ]
                    ] :
                    (object) [
                        'type' => 'ifThen',
                        'value' => [
                            $this->parse($conditionPart),
                            $this->parse($thenPart)
                        ]
                    ];
            }

            if (!$parsedPart) {
                throw SyntaxError::create(
                    'Unknown syntax error in expression ' . $expression . '.',
                    'Unknown syntax error.'
                );
            }

            $parsedPartList[] = $parsedPart;
        }

        if (count($parsedPartList) === 1) {
            return $parsedPartList[0];
        }

        return (object) [
            'type' => 'bundle',
            'value' => $parsedPartList,
        ];
    }

    private static function sliceByStartEnd(string $expression, int $start, int $end): string
    {
        return trim(
            substr(
                $expression,
                $start,
                $end - $start
            )
        );
    }

    private function stripComments(string &$expression, string &$modifiedExpression): void
    {
        $commentIndexStart = null;

        for ($i = 0; $i < strlen($modifiedExpression); $i++) {
            if (is_null($commentIndexStart)) {
                if (
                    $modifiedExpression[$i] === '/' &&
                    $i < strlen($modifiedExpression) - 1 &&
                    $modifiedExpression[$i + 1] === '/'
                ) {
                    $commentIndexStart = $i;
                }
            }
            else {
                if ($modifiedExpression[$i] === "\n" || $i === strlen($modifiedExpression) - 1) {
                    for ($j = $commentIndexStart; $j <= $i; $j++) {
                        $modifiedExpression[$j] = ' ';
                        $expression[$j] = ' ';
                    }

                    $commentIndexStart = null;
                }
            }
        }

        for ($i = 0; $i < strlen($modifiedExpression) - 1; $i++) {
            if (is_null($commentIndexStart)) {
                if ($modifiedExpression[$i] === '/' && $modifiedExpression[$i + 1] === '*') {
                    $commentIndexStart = $i;
                }
            }
            else {
                if ($modifiedExpression[$i] === '*' && $modifiedExpression[$i + 1] === '/') {
                    for ($j = $commentIndexStart; $j <= $i + 1; $j++) {
                        $modifiedExpression[$j] = ' ';
                        $expression[$j] = ' ';
                    }

                    $commentIndexStart = null;
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function parseArgumentListFromFunctionContent(string $functionContent): array
    {
        $functionContent = trim($functionContent);

        $isString = false;
        $isSingleQuote = false;

        if ($functionContent === '') {
            return [];
        }

        $commaIndexList = [];
        $braceCounter = 0;

        for ($i = 0; $i < strlen($functionContent); $i++) {
            if ($functionContent[$i] === "'" && ($i === 0 || $functionContent[$i - 1] !== "\\")) {
                if (!$isString) {
                    $isString = true;
                    $isSingleQuote = true;
                }
                else {
                    if ($isSingleQuote) {
                        $isString = false;
                    }
                }
            }
            else if ($functionContent[$i] === "\"" && ($i === 0 || $functionContent[$i - 1] !== "\\")) {
                if (!$isString) {
                    $isString = true;
                    $isSingleQuote = false;
                }
                else {
                    if (!$isSingleQuote) {
                        $isString = false;
                    }
                }
            }

            if (!$isString) {
                if ($functionContent[$i] === '(') {
                    $braceCounter++;
                }
                else if ($functionContent[$i] === ')') {
                    $braceCounter--;
                }
            }

            if ($braceCounter === 0 && !$isString && $functionContent[$i] === ',') {
                $commaIndexList[] = $i;
            }
        }

        $commaIndexList[] = strlen($functionContent);

        $argumentList = [];

        for ($i = 0; $i < count($commaIndexList); $i++) {
            if ($i > 0) {
                $previousCommaIndex = $commaIndexList[$i - 1] + 1;
            }
            else {
                $previousCommaIndex = 0;
            }

            $argument = trim(
                substr(
                    $functionContent,
                    $previousCommaIndex,
                    $commaIndexList[$i] - $previousCommaIndex
                )
            );

            $argumentList[] = $argument;
        }

        return $argumentList;
    }
}
