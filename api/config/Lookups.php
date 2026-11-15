<?php

declare(strict_types=1);

namespace App\Config;

//---------------------------------------------------------------------------
/**
 * Definitions of the issue lookup tables that share one REST controller (see _docs/TICKET_SPEC.md).
 *
 * Keys of a definition:
 *   table      Database table
 *   label      Singular label for messages
 *   permission Permission required to change records (reading needs view_issue or this permission)
 *   create_permissions Further permissions that allow creating a record (optional; editing and deleting need `permission`)
 *   fields     Columns besides id, name, key and the timestamps that can be written through the API
 *   protected  The table has a `key` column. Records with a key are protected system records
 *   creatable  Records can be created through the API
 *   deletable  Records can be deleted through the API
 *   replacement Column of the issues table that holds the record. Deleting a record that issues use
 *              requires a replacement (null: records can be deleted without one, the issues lose them)
 *   order      ORDER BY of the list
 */
final class Lookups
{
  //---------------------------------------------------------------------------
  /**
   * Returns all definitions by their URL segment.
   *
   * @return array<string, array>
   */
  public static function all(): array
  {
    $visual = ['description', 'icon', 'color', 'background_color'];

    return [
      'issue-types' => [
        'table' => 'issue_types',
        'label' => 'Issue type',
        'permission' => 'manage_issue_types',
        'fields' => [...$visual, 'sort_order', 'active'],
        'protected' => true,
        'creatable' => true,
        'deletable' => true,
        'replacement' => 'type_id',
        'order' => 't.sort_order, t.name',
      ],
      'status-categories' => [
        'table' => 'status_categories',
        'label' => 'Status category',
        'permission' => 'manage_status_categories',
        'fields' => $visual,
        'protected' => true,
        'creatable' => false,
        'deletable' => false,
        'order' => 't.id',
      ],
      'status' => [
        'table' => 'status',
        'label' => 'Status',
        'permission' => 'manage_status',
        'fields' => ['category_id', ...$visual, 'sort_order', 'active'],
        'protected' => true,
        'creatable' => true,
        'deletable' => true,
        'replacement' => 'status_id',
        'order' => 't.sort_order, t.name',
      ],
      'resolutions' => [
        'table' => 'resolutions',
        'label' => 'Resolution',
        'permission' => 'manage_resolutions',
        'fields' => [...$visual, 'sort_order', 'active'],
        'protected' => true,
        'creatable' => true,
        'deletable' => true,
        'replacement' => 'resolution_id',
        'order' => 't.sort_order, t.name',
      ],
      'priorities' => [
        'table' => 'priorities',
        'label' => 'Priority',
        'permission' => 'manage_priorities',
        'fields' => [...$visual, 'sort_order', 'active'],
        'protected' => true,
        'creatable' => true,
        'deletable' => true,
        'replacement' => 'priority_id',
        'order' => 't.sort_order, t.name',
      ],
      'products' => [
        'table' => 'products',
        'label' => 'Product',
        'permission' => 'manage_products',
        // The key (issue_key) is the prefix of the issue keys of the product (LD-12); see _docs/PRODUCTS.md
        'fields' => ['issue_key', ...$visual],
        'protected' => false,
        'creatable' => true,
        'deletable' => true,
        // Deleting a product that has issues (or is the default product) needs a replacement product: the issues move to it
        // (new numbers, the old keys stay valid as aliases)
        'replacement' => 'product_id',
        'order' => 't.name',
      ],
      'tags' => [
        'table' => 'tags',
        'label' => 'Tag',
        'permission' => 'manage_tags',
        // Besides manage_tags, everybody who can create or edit issues may create a tag (new tag on the issue forms)
        'create_permissions' => ['create_issue', 'edit_issue'],
        'fields' => ['color', 'background_color'],
        'protected' => false,
        'creatable' => true,
        'deletable' => true,
        'order' => 't.name',
      ],
    ];
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the definition of one lookup.
   *
   * @param string $slug The URL segment (e.g. issue-types)
   *
   * @return array|null
   */
  public static function get(string $slug): ?array
  {
    return self::all()[$slug] ?? null;
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the URL segments of all lookups.
   *
   * @return string[]
   */
  public static function slugs(): array
  {
    return array_keys(self::all());
  }
}
