<?php
if (!defined('ABSPATH')) exit;

class SPP_Margin {

    /**
     * Rules: ordered array of ['min'=>n, 'max'=>n|'', 'margin'=>n|''].
     * - min/max are inclusive bounds (price >= min AND price <= max)
     * - a blank max on the LAST rule means "and above" (no upper bound)
     * - a blank margin means "request a quote" (no price shown)
     */
    public static function tiers() {
        $t = get_option(SPP_OPT_MARGINS);
        if (!is_array($t) || empty($t)) {
            $t = array(
                array('min' => 0,     'max' => 1000,  'margin' => 200),
                array('min' => 1001,  'max' => 5000,  'margin' => 400),
                array('min' => 5001,  'max' => 10000, 'margin' => 600),
                array('min' => 10001, 'max' => '',    'margin' => ''), // above 10000 -> quote
            );
        }
        return $t;
    }

    // find the rule matching a price (min <= price <= max; blank max = no upper bound)
    public static function match($price) {
        $price = floatval($price);
        foreach (self::tiers() as $t) {
            $min   = ($t['min'] === '' || $t['min'] === null) ? 0 : floatval($t['min']);
            $noMax = ($t['max'] === '' || $t['max'] === null);
            $max   = $noMax ? null : floatval($t['max']);
            if ($price >= $min && ($noMax || $price <= $max)) return $t;
        }
        return null;
    }

    // true when the matching rule has a blank margin => request a quote, no price
    public static function is_quote($price) {
        $r = self::match($price);
        return $r && ($r['margin'] === '' || $r['margin'] === null);
    }

    // original price -> selling price (returns original if quote; caller checks is_quote first)
    public static function final_price($original) {
        $original = floatval($original);
        $r = self::match($original);
        if (!$r) return $original;                                        // uncovered (validation prevents)
        if ($r['margin'] === '' || $r['margin'] === null) return $original; // quote
        return $original + floatval($r['margin']);
    }

    /**
     * Validate a set of rules for gaps / overlaps / bad values.
     * Returns '' if valid, or an error message describing the first problem.
     */
    public static function validate($rules) {
        if (empty($rules)) return 'Add at least one price rule.';

        // basic value checks
        foreach ($rules as $i => $r) {
            $n = $i + 1;
            if ($r['min'] === '' || !is_numeric($r['min'])) return "Row $n: 'from' must be a number.";
            $isLast = ($i === count($rules) - 1);
            if (!$isLast && ($r['max'] === '' || !is_numeric($r['max']))) return "Row $n: 'to' must be a number (only the last row may be blank for 'and above').";
            if ($r['max'] !== '' && !is_numeric($r['max'])) return "Row $n: 'to' must be a number.";
            if ($r['max'] !== '' && floatval($r['max']) < floatval($r['min'])) return "Row $n: 'to' must be greater than or equal to 'from'.";
            if ($r['margin'] !== '' && !is_numeric($r['margin'])) return "Row $n: margin must be a number, or blank for a quote.";
        }

        // must be sorted and fully contiguous from the lowest, with no gap or overlap
        for ($i = 1; $i < count($rules); $i++) {
            $prevMax = floatval($rules[$i - 1]['max']);
            $curMin  = floatval($rules[$i]['min']);
            if ($curMin <= $prevMax) {
                return "Rows " . $i . " and " . ($i + 1) . " overlap (from " . $rules[$i]['min'] . " is not above the previous 'to' of " . $rules[$i - 1]['max'] . ").";
            }
            if ($curMin > $prevMax + 1) {
                return "Gap between rows " . $i . " and " . ($i + 1) . ": prices " . ($prevMax + 1) . " to " . ($curMin - 1) . " aren't covered. Set row " . ($i + 1) . "'s 'from' to " . ($prevMax + 1) . ".";
            }
        }
        return '';
    }

    // ---------------- per-category rules ----------------
    // Stored as: array( 'Men Watch' => array(rules...), 'Ladies Watch' => array(rules...) )
    // A category with its own rules uses them; anything else falls back to the global rules.

    public static function category_rules() {
        $r = get_option(SPP_OPT_CAT_MARGINS, array());
        return is_array($r) ? $r : array();
    }

    public static function set_category_rules($map) {
        update_option(SPP_OPT_CAT_MARGINS, is_array($map) ? $map : array());
    }

    // rules that apply to a given category name (falls back to global)
    public static function rules_for($category = '') {
        $map = self::category_rules();
        if ($category !== '' && isset($map[$category]) && !empty($map[$category])) return $map[$category];
        return self::tiers();
    }

    // match within an explicit rule set
    public static function match_in($rules, $price) {
        $price = floatval($price);
        foreach ($rules as $t) {
            $min   = ($t['min'] === '' || $t['min'] === null) ? 0 : floatval($t['min']);
            $noMax = ($t['max'] === '' || $t['max'] === null);
            $max   = $noMax ? null : floatval($t['max']);
            if ($price >= $min && ($noMax || $price <= $max)) return $t;
        }
        return null;
    }

    // category-aware versions used by the product upsert / re-price
    public static function is_quote_for($price, $category = '') {
        $r = self::match_in(self::rules_for($category), $price);
        return $r && ($r['margin'] === '' || $r['margin'] === null);
    }

    public static function final_price_for($original, $category = '') {
        $original = floatval($original);
        $r = self::match_in(self::rules_for($category), $original);
        if (!$r) return $original;
        if ($r['margin'] === '' || $r['margin'] === null) return $original;
        return $original + floatval($r['margin']);
    }

}
