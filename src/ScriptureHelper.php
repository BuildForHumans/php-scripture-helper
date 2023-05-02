<?php
namespace BuildForHumans\ScriptureHelper;

use InvalidArgumentException;
use Throwable;

class ScriptureHelper
{

	const BOOK_REGEX = '((I|II|III|IV|1|2|3|4|First|Second|Third|Fourth|Song\sof|Acts\sof\sthe)\s{0,1})?([A-Z][a-z]+)';
	const CV_REGEX = '((\d+(:\d+[a-z]?)?)([-—](\d+(:\d+[a-z]?)?))?)';

	public static function getUniqueRefs(?string $text = null): array
	{

		if (empty($text))
		{
			return [];
		}

		$refs = self::grepReferences($text);
		$refs = self::normalizeAndSplitReferences($refs);

		return array_unique($refs);

	}

	public static function grepReferences(?string $text = null): array
	{

		if (empty($text))
		{
			return [];
		}

		$bcvRegex = '\b' . self::BOOK_REGEX . '(\s{0,1})' . self::CV_REGEX . '(,\s{0,1}' . self::CV_REGEX . ')*';
		preg_match_all("/{$bcvRegex}/", $text, $matches);
		return $matches[0];

	}

	/**
	 * Turns a list of reader-friendly references, which may contain grouped references or non-standard book titles,
	 * into a list of normalized references, which contain standardized book titles and one chapter-verse marker per reference.
	 * i.e. Turns "Gen 1:1, 2:3-4" into ['Genesis 1:1', 'Genesis 2:3-4']
	 */
	public static function normalizeAndSplitReferences(array|string $refs = []): array
	{

		// Normalize the input type
		if (is_string($refs))
		{
			$refs = [$refs];
		}

		// Trim each item
		array_walk($refs, 'trim');

		// Now normalize each scripture reference

		$normalizedRefs = [];

		foreach($refs as $ref)
		{

			// Match/validate the book title

			preg_match('/'.self::BOOK_REGEX.'/', $ref, $bookMatches);
			if (empty($bookMatches))
			{
				continue;
			}

			try {

				$book = Bible::normalizeBookTitle($bookMatches[0] ?? null);

				// Remove book title from reference (to prevent ordinals from coming up as C/V matches)

				$ref = str_replace($bookMatches[0], '', $ref);

				// Match C/V markers

				preg_match_all('/'.self::CV_REGEX.'/', $ref, $cvMatches);
				$cvs = $cvMatches[0];

				// Expand grouped references

				foreach ($cvs as $cv)
				{
					$normalizedRefs[] = "{$book} {$cv}";
				}

			}
			catch (Throwable $e)
			{
				continue;
			}

		}

		return $normalizedRefs;

	}

	/**
	 * Parses chapter/verse markers (the numeric portion of a reference) into a start/end info object.
	 * (Does not validate/care whether the chapters/verses actually exist.)
	 *
	 * @param string $cv The chapter/verse portion of a reference
	 * @param ?string $book The book in which this reference is found
	 */
	public static function parseCv(string $cv, ?string $book = null): ScriptureReference
	{

		// Remove word characters
		// (Markers may contain letters denoting sub-verses. We discard these, because we're only supporting integer verse ranges for now.)
		$cv = preg_replace("/[A-Z][a-z]/", '', $cv);

		// Remove spaces
		$cv = preg_replace("/\s/", '', $cv);

		// Standardize dashes
		$cv = str_replace(['&ndash;', '–'], '-', $cv);

		// Standardize colons
		$cv = str_replace(['.'], ':', $cv);

		// Try to parse out the deets

		$parts = explode('-', $cv, 2);

		$startPart = $parts[0] ?? null;
		$endPart = $parts[1] ?? null;

		$startNums = $startPart ? explode(':', $startPart) : [];
		$endNums = $endPart ? explode(':', $endPart) : [];

		// Fix up incomplete/weird cases

		switch (count($startNums) * 10 + count($endNums))
		{

			case 10:
				// One full chapter ("1")
				$startChapter = $startNums[0];
				$startVerse = null;
				$endChapter = null;
				$endVerse = null;
				break;

			case 11:
				// A range of chapters ("1-2")
				$startChapter = $startNums[0];
				$startVerse = null;
				$endChapter = $endNums[0];
				$endVerse = null;
				break;

			case 12:
				// Full chapter to part of chapter ("1-2:3")
				$startChapter = $startNums[0];
				$startVerse = 1;
				$endChapter = $endNums[0];
				$endVerse = $endNums[1];
				break;

			case 20:
				// One verse ("1:2")
				$startChapter = $startNums[0];
				$startVerse = $startNums[1];
				$endChapter = null;
				$endVerse = null;
				break;

			case 21:
				// Multiple verses from one chapter ("1:2-3")
				$startChapter = $startNums[0];
				$startVerse = $startNums[1];
				$endChapter = $startNums[0];
				$endVerse = $endNums[0];
				break;

			case 22:
				// Multiple verses from across chapters ("1:2-3:4")
				$startChapter = $startNums[0];
				$startVerse = $startNums[1];
				$endChapter = $endNums[0];
				$endVerse = $endNums[1];
				break;

			default:
				throw new InvalidArgumentException("Badly formed chapter/verse reference.");

		}

		return new ScriptureReference(
			book: Bible::normalizeBookTitle($book),
			startChapter: $startChapter,
			startVerse: $startVerse,
			endChapter: $endChapter,
			endVerse: $endVerse,
		);

	}

	/**
	 * Expands a list of references, which may include chapter-verse ranges, into a list of single-verse references
	 * e.g. Turns "Genesis 1:1-3" into [{Genesis 1:1}, {Genesis 1:2}, {Genesis 1:3}]
	 *
	 * @return ScriptureReference[]
	 */
	public static function parseRefs(array $refs): array
	{

		// Get unique normalized references

		$refs = self::normalizeAndSplitReferences($refs);
		$refs = array_unique($refs);

		// Convert into reference objects

		$scriptureReferences = [];

		foreach($refs as $ref)
		{

			// Match/validate the book title

			preg_match('/'.self::BOOK_REGEX.'/', $ref, $bookMatches);
			if (empty($bookMatches))
			{
				continue;
			}

			try
			{

				$book = Bible::normalizeBookTitle($bookMatches[0] ?? null);

				/*
				 * We remove the book name so it doesn't mess with the CV parsing.
				 * Because these references are already normalized/split, we assume
				 * each now contains only one CV reference.
				 */

				$cv = trim(str_replace($bookMatches[0], '', $ref));
				if (empty($cv)) continue;

				$scriptureReference = self::parseCv($cv, $book);
				if ($scriptureReference) $scriptureReferences[] = $scriptureReference;

			}
			catch (Throwable $e)
			{
				// Aggressively fail-silent: Ignore anything that causes errors
				continue;
			}

		}

		return $scriptureReferences;

	}

}
