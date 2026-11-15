<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\IssueConfig;
use App\Utils\IssueKey;

require_once __DIR__ . '/../utils/IssueKey.php';

//---------------------------------------------------------------------------
/**
 * Builds the SQL condition of the issue search (the top-bar search and the list parameter q).
 *
 * The condition only narrows an issue query down; who may see an issue is still decided by
 * App\Services\IssueVisibility::scope(), which the caller always applies. Internal comments are only
 * searched for viewers who may read them, so a search can never reveal their content.
 *
 * An issue matches when
 *   - the whole term is an issue key (LD-123, a former key as well) or a number (the number of an issue of the default
 *     product): that issue (exact match), or
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
   * @param IssueConfig     $config     The issue settings (the default product)
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

    $byKey = $this->keyCondition($term, $alias, $params);
    if ($byKey !== null) {
      $condition = "($byKey OR $condition)";
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
   * Reads the term as an issue key ("LD-123", any letter case) or an issue number ("123") and builds the condition
   * that finds that issue: by its current key, by a former key (alias), or, for a bare number, by the number in the
   * default product.
   *
   * @param string $term   The search term
   * @param string $alias  The alias of the issues table
   * @param array  $params The parameters of the whole condition (extended)
   *
   * @return string|null The condition, or null if the term is something else
   */
  private function keyCondition(string $term, string $alias, array &$params): ?string
  {
    $term = trim($term);

    $key = IssueKey::parse($term);
    if ($key !== null) {
      [$productKey, $number] = $key;
      $params['s_key_product'] = $productKey;
      $params['s_key_number'] = $number;
      $params['s_key_alias'] = IssueKey::format($productKey, $number);

      return "(($alias.number = :s_key_number AND EXISTS (SELECT 1 FROM products sk WHERE sk.id = $alias.product_id AND sk.issue_key = :s_key_product))"
        . " OR EXISTS (SELECT 1 FROM issue_key_aliases sa WHERE sa.issue_id = $alias.id AND sa.alias_key = :s_key_alias))";
    }

    if (preg_match('/^[1-9]\d{0,8}$/', $term) === 1 && $this->config->defaultProductId() > 0) {
      $params['s_num_number'] = (int)$term;
      $params['s_num_product'] = $this->config->defaultProductId();

      return "($alias.number = :s_num_number AND $alias.product_id = :s_num_product)";
    }

    return null;
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
