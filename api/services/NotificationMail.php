<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Builds the HTML and text emails of the issue notifications (see "Email and notification events" in
 * _docs/TICKET_SPEC.md). The texts are in the system language (default_language: English, German, French or Spanish); the email is
 * the same layout as the password reset email. The mails only say what happened and link to the issue: the text of
 * a comment never leaves the application.
 */
class NotificationMail
{
  private const TEXTS = [
    'en' => [
      'hello' => 'Hello %s,',
      'open' => 'Open issue',
      'footer' => 'You receive this email because of your notification settings in your profile.',
      'issue_created' => 'The issue was created by %s.',
      'issue_assigned' => 'The issue was assigned to %2$s by %1$s.',
      'issue_unassigned' => 'The issue was unassigned by %s.',
      'status_changed' => 'The status of the issue was changed to %2$s by %1$s.',
      'comment_added' => 'A comment was added to the issue by %s.',
      'comment_added_internal' => 'An internal comment was added to the issue by %s.',
      'heading' => [
        'issue_created' => 'Issue created',
        'issue_assigned' => 'Issue assigned',
        'status_changed' => 'Status changed',
        'comment_added' => 'New comment',
      ],
      'status' => [
        'open' => 'Open',
        'waiting_for_agent' => 'Waiting for agent',
        'waiting_for_customer' => 'Waiting for customer',
        'pending' => 'Pending',
        'on_hold' => 'On hold',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
      ],
    ],
    'de' => [
      'hello' => 'Hallo %s,',
      'open' => 'Ticket öffnen',
      'footer' => 'Sie erhalten diese E-Mail aufgrund Ihrer Benachrichtigungseinstellungen im Profil.',
      'issue_created' => 'Das Ticket wurde von %s erstellt.',
      'issue_assigned' => 'Das Ticket wurde von %1$s an %2$s zugewiesen.',
      'issue_unassigned' => 'Die Zuweisung des Tickets wurde von %s aufgehoben.',
      'status_changed' => 'Der Status des Tickets wurde von %1$s auf %2$s geändert.',
      'comment_added' => 'Zum Ticket wurde von %s ein Kommentar hinzugefügt.',
      'comment_added_internal' => 'Zum Ticket wurde von %s ein interner Kommentar hinzugefügt.',
      'heading' => [
        'issue_created' => 'Ticket erstellt',
        'issue_assigned' => 'Ticket zugewiesen',
        'status_changed' => 'Status geändert',
        'comment_added' => 'Neuer Kommentar',
      ],
      'status' => [
        'open' => 'Offen',
        'waiting_for_agent' => 'Wartet auf Agent',
        'waiting_for_customer' => 'Wartet auf Kunde',
        'pending' => 'Ausstehend',
        'on_hold' => 'Zurückgestellt',
        'resolved' => 'Gelöst',
        'closed' => 'Geschlossen',
      ],
    ],
    'fr' => [
      'hello' => 'Bonjour %s,',
      'open' => 'Ouvrir le ticket',
      'footer' => 'Vous recevez cet e-mail en raison des paramètres de notification de votre profil.',
      'issue_created' => 'Le ticket a été créé par %s.',
      'issue_assigned' => 'Le ticket a été assigné à %2$s par %1$s.',
      'issue_unassigned' => "L'assignation du ticket a été retirée par %s.",
      'status_changed' => 'Le statut du ticket a été changé en %2$s par %1$s.',
      'comment_added' => 'Un commentaire a été ajouté au ticket par %s.',
      'comment_added_internal' => 'Un commentaire interne a été ajouté au ticket par %s.',
      'heading' => [
        'issue_created' => 'Ticket créé',
        'issue_assigned' => 'Ticket assigné',
        'status_changed' => 'Statut modifié',
        'comment_added' => 'Nouveau commentaire',
      ],
      'status' => [
        'open' => 'Ouvert',
        'waiting_for_agent' => "En attente de l'agent",
        'waiting_for_customer' => 'En attente du client',
        'pending' => 'En suspens',
        'on_hold' => 'Suspendu',
        'resolved' => 'Résolu',
        'closed' => 'Fermé',
      ],
    ],
    'es' => [
      'hello' => 'Hola %s,',
      'open' => 'Abrir incidencia',
      'footer' => 'Recibes este correo por los ajustes de notificaciones de tu perfil.',
      'issue_created' => 'La incidencia fue creada por %s.',
      'issue_assigned' => 'La incidencia fue asignada a %2$s por %1$s.',
      'issue_unassigned' => '%s quitó la asignación de la incidencia.',
      'status_changed' => 'El estado de la incidencia fue cambiado a %2$s por %1$s.',
      'comment_added' => '%s añadió un comentario a la incidencia.',
      'comment_added_internal' => '%s añadió un comentario interno a la incidencia.',
      'heading' => [
        'issue_created' => 'Incidencia creada',
        'issue_assigned' => 'Incidencia asignada',
        'status_changed' => 'Estado cambiado',
        'comment_added' => 'Nuevo comentario',
      ],
      'status' => [
        'open' => 'Abierta',
        'waiting_for_agent' => 'Esperando al agente',
        'waiting_for_customer' => 'Esperando al cliente',
        'pending' => 'Pendiente',
        'on_hold' => 'En pausa',
        'resolved' => 'Resuelta',
        'closed' => 'Cerrada',
      ],
    ],
  ];

  private string $appName;
  private string $baseUrl;
  private string $language;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param string $appName  The application name (application_name)
   * @param string $baseUrl  The application URL without trailing slash (application_url)
   * @param string $language The system language (default_language); an unknown one falls back to English
   */
  public function __construct(string $appName, string $baseUrl, string $language)
  {
    $this->appName = trim($appName) !== '' ? trim($appName) : 'LeweDesk';
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->language = isset(self::TEXTS[$language]) ? $language : 'en';
  }

  //---------------------------------------------------------------------------
  /**
   * Returns the application URL for links in emails: the application_url setting, falling back to APP_URL
   * from the environment. No trailing slash.
   *
   * @param array $settings The system settings
   *
   * @return string
   */
  public static function baseUrl(array $settings): string
  {
    $url = trim((string)($settings['application_url'] ?? ''));
    if ($url === '') {
      $url = (string)($_ENV['APP_URL'] ?? '');
    }

    return rtrim($url, '/');
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the link to an issue page.
   *
   * @param int $issueId The issue id
   *
   * @return string
   */
  public function issueLink(int $issueId): string
  {
    return $this->baseUrl . '/issue.html?id=' . $issueId;
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the email about an action on an issue.
   *
   * @param array  $issue     id, key and summary of the issue
   * @param array  $message   events (the event names of the action, in order), actor (display name), status_key,
   *                          status_name, assignee (display name or null for none), internal (comment is internal)
   * @param string $recipient The display name of the recipient
   *
   * @return array{0: string, 1: string, 2: string} Subject, HTML body and plain text body
   */
  public function build(array $issue, array $message, string $recipient): array
  {
    $texts = self::TEXTS[$this->language];
    $lines = array_map(fn(string $event): string => $this->line($event, $message), $message['events']);
    $heading = $texts['heading'][$message['events'][0]] ?? $texts['heading']['status_changed'];
    $key = (string)$issue['key'];
    $summary = (string)$issue['summary'];
    $link = $this->issueLink((int)$issue['id']);

    $subject = $this->appName . ': ' . $key . ' - ' . (mb_strlen($summary) > 100 ? mb_substr($summary, 0, 99) . '…' : $summary);

    $html = $this->html($heading, sprintf($texts['hello'], $recipient), $key, $summary, $lines, $texts['open'], $link, $texts['footer']);
    $plain = $this->appName . "\n\n"
      . sprintf($texts['hello'], $recipient) . "\n\n"
      . $key . ' - ' . $summary . "\n\n"
      . implode("\n", $lines) . "\n\n"
      . $link . "\n\n"
      . $texts['footer'] . "\n";

    return [$subject, $html, $plain];
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the sentence for one event of the action.
   *
   * @param string $event   The event name
   * @param array  $message The message (see build())
   *
   * @return string
   */
  private function line(string $event, array $message): string
  {
    $texts = self::TEXTS[$this->language];
    $actor = (string)$message['actor'];

    return match ($event) {
      'issue_assigned' => ($message['assignee'] ?? null) === null
        ? sprintf($texts['issue_unassigned'], $actor)
        : sprintf($texts['issue_assigned'], $actor, (string)$message['assignee']),
      'status_changed' => sprintf($texts['status_changed'], $actor, $this->statusName($message)),
      'comment_added' => sprintf(!empty($message['internal']) ? $texts['comment_added_internal'] : $texts['comment_added'], $actor),
      default => sprintf($texts['issue_created'], $actor),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the name of the new status: the translation of a protected status, else the name as stored.
   *
   * @param array $message The message (see build())
   *
   * @return string
   */
  private function statusName(array $message): string
  {
    return self::TEXTS[$this->language]['status'][(string)($message['status_key'] ?? '')] ?? (string)($message['status_name'] ?? '');
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the HTML body.
   *
   * @param string   $heading The heading
   * @param string   $hello   The greeting
   * @param string   $key     The issue key
   * @param string   $summary The issue summary
   * @param string[] $lines   What happened
   * @param string   $button  The label of the button
   * @param string   $link    The link to the issue
   * @param string   $footer  The note below the card
   *
   * @return string
   */
  private function html(string $heading, string $hello, string $key, string $summary, array $lines, string $button, string $link, string $footer): string
  {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $font = "font-family:'Poppins','Segoe UI',Helvetica,Arial,sans-serif;";
    $icon = $this->baseUrl . '/assets/img/icons/app-icon-128.png';

    $items = '';
    foreach ($lines as $line) {
      $items .= '<p style="margin:0 0 10px;">' . $e($line) . '</p>';
    }

    return '<!DOCTYPE html><html lang="' . $this->language . '"><head><meta charset="UTF-8">'
      . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
      . '<meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark">'
      . '<title>' . $e($this->appName) . '</title></head>'
      . '<body style="margin:0;padding:0;background-color:#121212;' . $font . 'color:#dee2e6;">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#121212;"><tr><td align="center" style="padding:32px 12px;">'
      . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#1e1e1e;border:1px solid #333333;border-radius:8px;">'
      // Header
      . '<tr><td style="background-color:#212631;border-bottom:1px solid #323a49;border-radius:8px 8px 0 0;padding:20px 32px;">'
      . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
      . '<td style="padding-right:14px;"><img src="' . $e($icon) . '" width="40" height="40" alt="' . $e($this->appName) . '" style="display:block;border:0;"></td>'
      . '<td style="' . $font . 'font-size:20px;font-weight:600;color:#ffffff;">' . $e($this->appName) . '</td>'
      . '</tr></table></td></tr>'
      // Body
      . '<tr><td style="padding:36px 32px 12px;' . $font . 'font-size:15px;line-height:1.6;color:#dee2e6;">'
      . '<h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#ffffff;">' . $e($heading) . '</h1>'
      . '<p style="margin:0 0 16px;">' . $e($hello) . '</p>'
      . '<p style="margin:0 0 20px;padding:12px 16px;background-color:#252525;border-left:3px solid #5856d6;border-radius:4px;">'
      . '<strong style="color:#ffffff;">' . $e($key) . '</strong><br>' . $e($summary) . '</p>'
      . $items
      . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 28px;"><tr>'
      . '<td align="center" style="background-color:#5856d6;border-radius:6px;">'
      . '<a href="' . $e($link) . '" style="display:inline-block;padding:13px 30px;' . $font . 'font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">' . $e($button) . '</a>'
      . '</td></tr></table>'
      . '</td></tr>'
      // Notice
      . '<tr><td style="padding:0 32px 32px;' . $font . 'font-size:13px;line-height:1.6;color:#aaaaaa;">'
      . '<div style="border-top:1px solid #333333;padding-top:20px;">'
      . '<p style="margin:0 0 8px;word-break:break-all;"><a href="' . $e($link) . '" style="color:#0f90fb;text-decoration:none;">' . $e($link) . '</a></p>'
      . '<p style="margin:0;">' . $e($footer) . '</p>'
      . '</div></td></tr>'
      // Footer
      . '<tr><td align="center" style="background-color:#1a1a1a;border-top:1px solid #333333;border-radius:0 0 8px 8px;padding:18px 32px;' . $font . 'font-size:12px;color:#999999;">'
      . '&copy; ' . date('Y') . ' by <a href="https://lewe.com" style="color:#0f90fb;text-decoration:none;">Lewe Labs</a>'
      . '</td></tr>'
      . '</table></td></tr></table></body></html>';
  }
}
