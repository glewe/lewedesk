<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\AuditLog;
use App\Services\AuthPolicy;

//---------------------------------------------------------------------------
/**
 * REST controller for the profile of a user (self service).
 *
 *   GET  /profile/{id}          read
 *   PUT  /profile/{id}          update email, names and (optionally) password
 *   POST /profile/{id}/avatar   set the avatar: multipart "file" upload or JSON {"sample": "name.png"}
 *   GET  /avatars/{file}        serve an uploaded avatar
 *
 * The API has no server side session yet, so the user id comes from the client. Changing the password
 * therefore requires the current password.
 */
class ProfileController
{
  private const MAX_UPLOAD_BYTES = 1048576;
  private const IMAGE_TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
  private const MIME_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

  private UserModel $model;
  private AuditLog $audit;
  private string $uploadDir;
  private ?AuthPolicy $ldap;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param UserModel $model     The user model
   * @param AuditLog  $audit     The audit log
   * @param string    $uploadDir Directory for uploaded avatars
   * @param AuthPolicy|null $ldap Tells who authenticates against LDAP or OIDC (no password change for those users)
   */
  public function __construct(UserModel $model, AuditLog $audit, string $uploadDir, ?AuthPolicy $ldap = null)
  {
    $this->ldap = $ldap;
    $this->model = $model;
    $this->audit = $audit;
    $this->uploadDir = rtrim($uploadDir, '/\\');
  }

  //---------------------------------------------------------------------------
  /**
   * Dispatches a /profile request.
   *
   * @param string      $method HTTP method
   * @param int         $id     The user id
   * @param string|null $action Path segment after the id ("avatar" or null)
   *
   * @return void
   */
  public function handle(string $method, int $id, ?string $action): void
  {
    if (!$this->model->getProfile($id)) {
      $this->fail(404, 'User not found');
      return;
    }

    if ($action === 'avatar') {
      $method === 'POST' ? $this->setAvatar($id) : $this->fail(405, 'Method not allowed');
      return;
    }

    match ($method) {
      'GET' => $this->respond(['status' => 'success', 'data' => $this->withMethod($this->model->getProfile($id))]),
      'PUT' => $this->update($id),
      default => $this->fail(405, 'Method not allowed'),
    };
  }

  //---------------------------------------------------------------------------
  /**
   * Serves an uploaded avatar file.
   *
   * @param string $file The file name
   *
   * @return void
   */
  public function serveAvatar(string $file): void
  {
    $path = $this->uploadDir . '/' . $file;
    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*\.(png|jpg|gif|webp)$/', $file, $m) || !is_file($path)) {
      $this->fail(404, 'Avatar not found');
      return;
    }

    // Files are named after the username and get overwritten, so the browser must revalidate
    $etag = '"' . md5($file . filemtime($path) . filesize($path)) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
      http_response_code(304);
      return;
    }

    header('Content-Type: ' . self::MIME_TYPES[$m[1]]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
  }

  //---------------------------------------------------------------------------
  /**
   * Updates email, names and optionally the password.
   */
  private function update(int $id): void
  {
    $input = $this->input();
    $email = trim((string)($input['email'] ?? ''));
    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $currentPassword = (string)($input['current_password'] ?? '');

    // An LDAP or SSO user has no password in LeweDesk; it is changed where the accounts are managed
    $authMethod = $this->withMethod($this->model->getProfile($id))['auth_method'];
    if ($password !== '' && $authMethod !== AuthPolicy::LOCAL) {
      $this->fail(403, 'The password of ' . ($authMethod === AuthPolicy::OIDC ? 'an SSO' : 'an LDAP') . ' user cannot be changed here.');
      return;
    }

    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 100;
    $other = $validEmail ? $this->model->findByEmail($email) : null;

    $error = null;
    if (!$validEmail) {
      $error = 'Invalid email address';
    } elseif (mb_strlen($firstName) > 50 || mb_strlen($lastName) > 50) {
      $error = 'First and last name must not be longer than 50 characters';
    } elseif ($other && (int)$other['id'] !== $id) {
      $error = 'Email is already registered';
    } elseif ($password !== '' && strlen($password) < 8) {
      $error = 'Password must be at least 8 characters';
    } elseif ($password !== '' && !password_verify($currentPassword, (string)$this->model->getPasswordHash($id))) {
      $error = 'The current password is incorrect';
    }

    if ($error !== null) {
      $this->fail(400, $error);
      return;
    }

    $data = [
      'email' => $email,
      'first_name' => $firstName !== '' ? $firstName : null,
      'last_name' => $lastName !== '' ? $lastName : null,
      'password' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
    ];

    // The change and its audit entries stand or fall together (the actor is the logged in user, who may be an administrator)
    $this->audit->transaction(function () use ($id, $data): void {
      $before = $this->model->getProfile($id) ?? [];
      $this->model->updateProfile($id, $data);

      $username = (string)($before['username'] ?? '');
      $diff = AuditLog::diff($before, $data, ['email', 'first_name', 'last_name']);
      if ($diff['new'] !== []) {
        $this->audit->log('user.updated', null, 'user', $id, $username, $diff['old'], $diff['new']);
      }
      if ($data['password'] !== null) {
        $this->audit->log('auth.password_changed', null, 'user', $id, $username, ['password' => AuditLog::MASK], ['password' => AuditLog::MASK]);
      }
    });
    $this->respond(['status' => 'success', 'message' => 'Profile updated', 'data' => $this->withMethod($this->model->getProfile($id))]);
  }

  //---------------------------------------------------------------------------
  /**
   * Adds auth_method ("ldap", "oidc" or "local") to a profile for the page.
   *
   * @param array $profile The profile
   *
   * @return array
   */
  private function withMethod(array $profile): array
  {
    $profile['auth_method'] = $this->ldap !== null ? $this->ldap->method((string)$profile['username']) : AuthPolicy::LOCAL;

    return $profile;
  }

  //---------------------------------------------------------------------------
  /**
   * Sets the avatar from an upload or a sample image.
   */
  private function setAvatar(int $id): void
  {
    $profile = $this->model->getProfile($id);
    $old = (string)$profile['avatar'];

    if (isset($_FILES['file'])) {
      $avatar = $this->storeUpload($_FILES['file'], $this->fileBaseName($profile));
      if ($avatar === null) {
        return;
      }
    } else {
      $sample = (string)($this->input()['sample'] ?? '');
      if (!preg_match('/^[A-Za-z0-9_-]+\.png$/', $sample)) {
        $this->fail(400, 'Invalid sample avatar');
        return;
      }
      $avatar = 'sample/' . $sample;
    }

    $this->model->setAvatar($id, $avatar);
    if (str_starts_with($old, 'upload/') && $old !== $avatar) {
      @unlink($this->uploadDir . '/' . basename($old));
    }
    $this->respond(['status' => 'success', 'message' => 'Avatar updated', 'data' => $this->model->getProfile($id)]);
  }

  //---------------------------------------------------------------------------
  /**
   * Builds the file name (without extension) of an uploaded avatar: the username, or "user-<id>" if the
   * username is not safe to use as a file name.
   *
   * @param array $profile The user profile
   *
   * @return string
   */
  private function fileBaseName(array $profile): string
  {
    $username = (string)$profile['username'];
    return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $username) ? $username : 'user-' . $profile['id'];
  }

  //---------------------------------------------------------------------------
  /**
   * Validates and stores an uploaded image.
   *
   * @param array  $file     The $_FILES entry
   * @param string $baseName File name without extension
   *
   * @return string|null "upload/<file>", or null after an error response was sent
   */
  private function storeUpload(array $file, string $baseName): ?string
  {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
      $this->fail(400, 'The image must not be larger than 1 MB');
      return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
      $this->fail(400, 'No file uploaded');
      return null;
    }

    $info = @getimagesize($file['tmp_name']);
    $extension = $info ? (self::IMAGE_TYPES[$info[2]] ?? null) : null;
    if ($extension === null) {
      $this->fail(400, 'The file must be a PNG, JPG, GIF or WEBP image');
      return null;
    }

    if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
      $this->fail(500, 'The upload folder could not be created');
      return null;
    }

    $name = $baseName . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $this->uploadDir . '/' . $name)) {
      $this->fail(500, 'The image could not be saved');
      return null;
    }

    return 'upload/' . $name;
  }

  //---------------------------------------------------------------------------
  /**
   * Reads the JSON request body.
   */
  private function input(): array
  {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON response.
   */
  private function respond(array $payload, int $status = 200): void
  {
    http_response_code($status);
    echo json_encode($payload);
  }

  //---------------------------------------------------------------------------
  /**
   * Sends a JSON error response.
   */
  private function fail(int $status, string $message): void
  {
    $this->respond(['status' => 'error', 'message' => $message], $status);
  }
}
