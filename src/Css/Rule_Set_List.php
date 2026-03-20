<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Css;

/**
 * This class represents a series of CSS rule sets as defined in the specs: https://drafts.csswg.org/css2/#rule-sets.
 * They are optionally enclosed in a block at-rule - see https://drafts.csswg.org/css-syntax/#at-rules.
 *
 * @internal
 */
final class Rule_Set_List
{
    /**
     * This holds the full at-rule specification, such as `@media (min-width: 400px)`.
     * If it is empty, the rule sets are not within an at-rule.
     *
     * @var string
     */
    private $at_rule;
    /**
     * @var list<RuleSet>
     */
    private $rule_sets = [];
    public function __construct(string $at_rule)
    {
        $this->at_rule = $at_rule;
    }
    public function get_at_rule(): string
    {
        return $this->at_rule;
    }
    public function append_rule_set(Rule_Set $rule_set): self
    {
        $this->rule_sets[] = $rule_set;
        return $this;
    }
    /**
     * @return list<RuleSet>
     */
    public function get_rule_sets(): array
    {
        return $this->rule_sets;
    }
}