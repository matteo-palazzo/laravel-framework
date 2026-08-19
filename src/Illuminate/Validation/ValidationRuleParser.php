<?php

namespace Illuminate\Validation;

use Illuminate\Contracts\Validation\CompilableRules;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ValidationRuleParser
{
    /**
     * The parsed validation rules.
     *
     * @var array<string, array{0: string, 1: array}>
     */
    private $parsedRules = [];

    /**
     * Extract the rule name and parameters from a rule.
     */
    public function parse(mixed $rule): array
    {
        if (! is_string($rule)) {
            return $this->parseRule($rule);
        }

        return $this->parsedRules[$rule] ??= $this->parseRule($rule);
    }

    /**
     * Extract the rule name and parameters from an uncached rule.
     */
    protected function parseRule(mixed $rule): array
    {
        if ($rule instanceof RuleContract || $rule instanceof CompilableRules) {
            return [$rule, []];
        }

        if (is_array($rule)) {
            $rule = $this->parseArrayRule($rule);
        } else {
            $rule = $this->parseStringRule($rule);
        }

        $rule[0] = $this->normalizeRule($rule[0]);

        return $rule;
    }

    /**
     * Parse an array based rule.
     */
    protected function parseArrayRule(array $rule): array
    {
        return [Str::studly(trim(Arr::get($rule, 0, ''))), array_slice($rule, 1)];
    }

    /**
     * Parse a string based rule.
     */
    protected function parseStringRule(string $rule): array
    {
        $parameters = [];

        if (str_contains($rule, ':')) {
            [$rule, $parameter] = explode(':', $rule, 2);

            $parameters = $this->parseParameters($rule, $parameter);
        }

        return [Str::studly(trim($rule)), $parameters];
    }

    /**
     * Parse a parameter list.
     */
    protected function parseParameters(string $rule, string $parameter): array
    {
        return $this->ruleIsRegex($rule) ? [$parameter] : str_getcsv($parameter, escape: '\\');
    }

    /**
     * Determine if the rule is a regular expression.
     */
    protected function ruleIsRegex(string $rule): bool
    {
        return in_array(strtolower($rule), ['regex', 'not_regex', 'notregex'], true);
    }

    /**
     * Normalize a rule so that short types are accepted.
     */
    protected function normalizeRule(string $rule): string
    {
        return match ($rule) {
            'Int' => 'Integer',
            'Bool' => 'Boolean',
            default => $rule,
        };
    }

}
