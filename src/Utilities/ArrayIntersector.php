<?php

declare (strict_types=1);
namespace Pelago\Emogrifier\Utilities;

/**
 * When computing many array intersections using the same array, it is more efficient to use `array_flip()` first and
 * then `array_intersect_key()`, than `array_intersect()`.  See the discussion at
 * {@link https://stackoverflow.com/questions/6329211/php-array-intersect-efficiency Stack Overflow} for more
 * information.
 *
 * Of course, this is only possible if the arrays contain integer or string values, and either don't contain duplicates,
 * or that fact that duplicates will be removed does not matter.
 *
 * This class takes care of the detail.
 *
 * @internal
 */
final class Array_Intersector
{
    /**
     * the array with which the object was constructed, with all its keys exchanged with their associated values
     *
     * @var array<array-key, array-key>
     */
    private $inverted_array;
    /**
     * Constructs the object with the array that will be reused for many intersection computations.
     *
     * @param array<array-key, array-key> $array
     */
    public function __construct(array $array)
    {
        $this->inverted_array = \array_flip($array);
    }
    /**
     * Computes the intersection of `$array` and the array with which this object was constructed.
     *
     * @param array<array-key, array-key> $array
     *
     * @return array<array-key, array-key>
     *         Returns an array containing all of the values in `$array` whose values exist in the array
     *         with which this object was constructed.  Note that keys are preserved, order is maintained, but
     *         duplicates are removed.
     */
    public function intersect_with(array $array): array
    {
        $inverted_array = \array_flip($array);
        $inverted_intersection = \array_intersect_key($inverted_array, $this->inverted_array);
        return \array_flip($inverted_intersection);
    }
}