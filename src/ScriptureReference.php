<?php
namespace BuildForHumans\ScriptureHelper;

class ScriptureReference
{

	/*
	 * This class doesn't care about validation. If you write a reference to Genesis 9-2 or Genesis 1:3-1, it'll probably choke. So, don't do that.
	 */

	public function __construct(
		public ?string $book = null,
		public ?int $startChapter = null,
		public ?int $startVerse = null,
		public ?int $endChapter = null,
		public ?int $endVerse = null,
	)
	{

		// Fix up references for single-chapter books
		// (We standardize the "Jude 1" form, rather than "Jude 1:1")

		if (
			$this->book
			&& Bible::getChapterCount($this->book) == 1
			&& $this->startVerse
		)
		{
			$this->startChapter = $this->startVerse;
			$this->endChapter = $this->endVerse;
			$this->startVerse = null;
			$this->endVerse = null;
		}

	}

	public function __toString()
	{
		// TODO
		return '';
	}

	public function isChapterOnly(): bool
	{
		return $this->startChapter && !$this->startVerse && !$this->endVerse;
	}

	public function isSingleChapter(): bool
	{
		return $this->startChapter && (!$this->endChapter || $this->startChapter == $this->endChapter);
	}

	public function isMultipleChapter(): bool
	{
		return $this->startChapter && $this->endChapter && $this->startChapter != $this->endChapter;
	}

	public function isSingleVerse(): bool
	{
		return $this->startChapter && $this->startVerse && !$this->endChapter && !$this->endVerse;
	}

}
