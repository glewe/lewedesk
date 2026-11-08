<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\IssueConfig;

//---------------------------------------------------------------------------
/**
 * Builds the SQL condition of the issue search (the top-bar search and the list parameter q).
 *
 * The condition only narrows an issue query down; who may see an issue is still decided by
 * App\Services\IssueVisibility::scope(), which the caller always applies. Internal comments are only
 * searched for viewers who may read them, so a search can never reveal their content.
 *
 * An issue matches when
 *   - the whole term is an issue key (LD-123) or a number: the issue with that id (exact match), or
 *   - every word of the term is found in the summary, the description, the text of a comment, the
 *     product name or a tag name.
 *
 * Words of three or more characters use the FULLTEXT indexes (as a prefix search) for summary,
 * description and comments. Shorter words (InnoDB does not index them), and the names of products and
 * tags, use LIKE.
 */
class IssueSearch
{
  /** The longest search term */
  public const MAX_LENGTH = 100;

  /** Words beyond this number are ignored */
  private const MAX_WORDS = 8;

  /** The shortest word that the FULLTEXT index knows (innodb_ft_min_token_size) */
  private const MIN_FULLTEXT = 3;

  private IssueVisibility $visibility;
  private IssueConfig $config;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param IssueVisibility $visibility The visibility rules (internal comments)
   * @param IssueConfig     $config     The issue settings (the prefix of the issue key)
   */
  public function __construct(IssueVisibility $visibility, IssueConfig $config)
  {
    $this->visibility = $visibility;
    $this->config = $config;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the condition for a search term.
   *
   * @param string $term  The search term as typed (at most MAX_LENGTH characters)
   * @param string $alias The alias of the issues table in the query
   *
   * @return array{0: string, 1: array}|null The condition and its parameters (named "s_..."), or null if the term is empty
   */
  public function condition(string $term, string $alias = 'i'): ?array
  {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
      throw new \InvalidArgumentException('Invalid table alias');
    }

    if (trim($term) === '') {
      return null;
    }

    // A term without letters or digits ("%%", "--") finds nothing, it must not list everything
    $words = $this->words($term);
    if (empty($words)) {
      return ['1 = 0', []];
    }

    $params = [];
    $perWord = [];
    foreach ($words as $index => $word) {
      $perWord[] = $this->wordCondition($word, $index, $alias, $params);
    }
    $condition = '(' . implode(' AND ', $perWord) . ')';

    $id = $this->issueId($term);
    if ($id !== null) {
      $condition = "($alias.id = :s_id OR $condition)";
      $params['s_id'] = $id;
    }

    return [$condition, $params];
  }

  //---------------------------------------------------------------------------
  /**
   * Splits a term into words. Everything but letters, digits and underscores separates words, which also
   * keeps the operators of the FULLTEXT boolean mode out of the query. Repeated words count once.
   *
   * @param string $term The search term
   *
   * @return string[]
   */
  private function words(string $term): array
  {
    $parts = preg_split('/[^\p{L}\p{N}_]+/u', $term, -1, PREG_SPLIT_NO_EMPTY);

    return array_slice(array_values(array_unique($parts === false ? [] : $parts)), 0, self::MAX_WORDS);
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the term as an issue key ("LD-123", any letter case) or an issue number ("123").
   *
   * @param string $term The search term
   *
   * @return int|null The issue id, or null if the term is something else
   */
  private function issueId(string $term): ?int
  {
    $pattern = '/^(?:' . preg_quote($this->config->keyPrefix(), '/') . '-)?([1-9]\d{0,8})$/i';

    return preg_match($pattern, trim($term), $match) === 1 ? (int)$match[1] : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the condition for one word: found in any of the searched places.
   *
   * @param string $word   The word
   * @param int    $index  The position of the word (makes the parameter names unique)
   * @param string $alias  The alias of the issues table
   * @param array  $params The parameters of the whole condition (extended)
   *
   * @return string
   */
  private function wordCondition(string $word, int $index, string $alias, array &$params): string
  {
    $internal = $this->visibility->canViewInternalComments() ? '' : ' AND cm.is_internal = 0';
    $like = "s_like$index";
    $params[$like] = '%' . addcslashes($word, '\\%_') . '%';

    // Product and tag names are short and few, LIKE is enough
    $places = [
      "EXISTS (SELECT 1 FROM products sp WHERE sp.id = $alias.product_id AND sp.name LIKE :$like)",
      "EXISTS (SELECT 1 FROM issue_tag st JOIN tags sg ON sg.id = st.tag_id WHERE st.issue_id = $alias.id AND sg.name LIKE :$like)",
    ];

    // A deleted comment keeps its row but no text, so it is left out anyway
    $commentWhere = "cm.issue_id = $alias.id AND cm.deleted_at IS NULL$internal";

    if (mb_strlen($word) >= self::MIN_FULLTEXT) {
      $match = "s_match$index";
      $params[$match] = $word . '*';
      array_unshift(
        $places,
        "MATCH($alias.summary, $alias.description) AGAINST (:$match IN BOOLEAN MODE)",
        "EXISTS (SELECT 1 FROM comments cm WHERE $commentWhere AND MATCH(cm.body) AGAINST (:$match IN BOOLEAN MODE))"
      );
    } else {
      array_unshift(
        $places,
        "($alias.summary LIKE :$like OR $alias.description LIKE :$like)",
        "EXISTS (SELECT 1 FROM comments cm WHERE $commentWhere AND cm.body LIKE :$like)"
      );
    }

    return '(' . implode(' OR ', $places) . ')';
  }
}
