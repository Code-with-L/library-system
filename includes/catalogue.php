<?php
/**
 * Shared catalogue and book-copy service helpers for Phase 3.
 *
 * These helpers are server-side services for controller pages. Circulation
 * service functions in includes/circulation.php remain authoritative.
 */

/**
 * Status values allowed on physical copies.
 *
 * @return array<int, string>
 */
function catalogue_copy_status_allowed(): array
{
    return ['available', 'reserved', 'issued', 'maintenance', 'archived'];
}

/**
 * Human-readable message for catalogue error codes.
 */
function catalogue_error_message(string $code): string
{
    return match ($code) {
        'book_not_available' => 'The book is not currently available.',
        'no_available_copies' => 'There are no available copies of this title.',
        'email_not_verified' => 'Please verify your email address before requesting books.',
        'max_active_borrowings' => 'You have reached the maximum number of active loans.',
        'duplicate_active_request' => 'You already have an active request for this title.',
        'request_not_closeable' => 'This request can no longer be cancelled.',
        'not_owner' => 'You do not own this borrow request.',
        default => 'The request could not be completed.',
    };
}

/**
 * Fetch catalogue books for the student/staff browsing lists.
 *
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function catalogue_books_fetch(PDO $pdo, array $filters = [], int $offset = 0, int $limit = 25): array
{
    $where = ['b.status = \'active\''];
    $params = [];
    if (!empty($filters['category_id']) && filter_var($filters['category_id'], FILTER_VALIDATE_INT) !== false) {
        $where[] = 'b.category_id = :category_id';
        $params['category_id'] = (int) $filters['category_id'];
    }
    if (!empty($filters['search'])) {
        // Escape LIKE wildcards so '%' and '_' are matched literally.
        // '!' is used as the escape character to avoid MySQL backslash rules.
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search']);
        $like = '%' . $term . '%';
        $where[] = "(b.title LIKE :q_title ESCAPE '!' OR b.author LIKE :q_author ESCAPE '!' OR b.isbn LIKE :q_isbn ESCAPE '!')";
        $params['q_title'] = $like;
        $params['q_author'] = $like;
        $params['q_isbn'] = $like;
    }
    $whereSql = implode(' AND ', $where);
    $statement = $pdo->prepare(
        'SELECT b.id, b.category_id, b.isbn, b.title, b.author, b.publisher,
                b.publication_year, b.description, b.status, b.cover_image, c.name AS category_name
           FROM books b
           INNER JOIN categories c ON c.id = b.category_id
          WHERE ' . $whereSql . '
          ORDER BY b.title ASC, b.id ASC
          LIMIT :limit OFFSET :offset'
    );
    $statement->execute($params + ['limit' => $limit, 'offset' => $offset]);
    return (array) $statement->fetchAll();
}

/**
 * Count catalogue books matching the filter.
 *
 * @param array<string, mixed> $filters
 */
function catalogue_books_count(PDO $pdo, array $filters = []): int
{
    $where = ['b.status = \'active\''];
    $params = [];
    if (!empty($filters['category_id']) && filter_var($filters['category_id'], FILTER_VALIDATE_INT) !== false) {
        $where[] = 'b.category_id = :category_id';
        $params['category_id'] = (int) $filters['category_id'];
    }
    if (!empty($filters['search'])) {
        // Escape LIKE wildcards so '%' and '_' are matched literally.
        // '!' is used as the escape character to avoid MySQL backslash rules.
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search']);
        $like = '%' . $term . '%';
        $where[] = "(b.title LIKE :q_title ESCAPE '!' OR b.author LIKE :q_author ESCAPE '!' OR b.isbn LIKE :q_isbn ESCAPE '!')";
        $params['q_title'] = $like;
        $params['q_author'] = $like;
        $params['q_isbn'] = $like;
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM books b WHERE ' . implode(' AND ', $where));
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

/**
 * Fetch one catalogue book with its category name.
 *
 * @return array<string, mixed>|null
 */
function catalogue_book_get(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT b.id, b.category_id, b.isbn, b.title, b.author, b.publisher,
                b.publication_year, b.description, b.status, b.cover_image, b.created_by, b.created_at,
                c.name AS category_name
           FROM books b
           INNER JOIN categories c ON c.id = b.category_id
          WHERE b.id = :id'
    );
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: null;
}

/**
 * Fetch all physical copies for a book.
 *
 * @return array<int, array<string, mixed>>
 */
function catalogue_copies_for_book(PDO $pdo, int $bookId): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM book_copies WHERE book_id = :book_id ORDER BY copy_code ASC'
    );
    $statement->execute(['book_id' => $bookId]);
    return (array) $statement->fetchAll();
}

/**
 * Fetch available copies for a book.
 *
 * @return array<int, array<string, mixed>>
 */
function catalogue_available_copies(PDO $pdo, int $bookId): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM book_copies WHERE book_id = :book_id AND status = \'available\' ORDER BY copy_code ASC'
    );
    $statement->execute(['book_id' => $bookId]);
    return (array) $statement->fetchAll();
}

/**
 * Summary counts for copies of a book by status.
 *
 * @return array<string, int>
 */
function catalogue_book_copy_summary(PDO $pdo, int $bookId): array
{
    $summary = ['available' => 0, 'reserved' => 0, 'issued' => 0, 'maintenance' => 0, 'archived' => 0];
    $statement = $pdo->prepare('SELECT status, COUNT(*) AS n FROM book_copies WHERE book_id = :book_id GROUP BY status');
    $statement->execute(['book_id' => $bookId]);
    foreach ($statement->fetchAll() as $row) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $summary)) {
            $summary[$status] = (int) $row['n'];
        }
    }
    return $summary;
}

/**
 * Fetch a copy by ID.
 *
 * @return array<string, mixed>|null
 */
function catalogue_copy_get(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM book_copies WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: null;
}

/**
 * Fetch requests for staff and student history views.
 *
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function catalogue_requests_fetch(PDO $pdo, array $filters = [], int $limit = 50): array
{
    $where = [];
    $params = [];
    if (!empty($filters['user_id']) && filter_var($filters['user_id'], FILTER_VALIDATE_INT) !== false) {
        $where[] = 'br.user_id = :user_id';
        $params['user_id'] = (int) $filters['user_id'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'approved', 'rejected', 'cancelled', 'fulfilled'], true)) {
        $where[] = 'br.status = :status';
        $params['status'] = (string) $filters['status'];
    }
    if (!empty($filters['book_id']) && filter_var($filters['book_id'], FILTER_VALIDATE_INT) !== false) {
        $where[] = 'br.book_id = :book_id';
        $params['book_id'] = (int) $filters['book_id'];
    }
    if (!empty($filters['search'])) {
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search']);
        $like = '%' . $term . '%';
        $where[] = "(b.title LIKE :q_title ESCAPE '!' OR b.author LIKE :q_author ESCAPE '!' OR u.full_name LIKE :q_full_name ESCAPE '!' OR u.email LIKE :q_email ESCAPE '!')";
        $params['q_title'] = $like;
        $params['q_author'] = $like;
        $params['q_full_name'] = $like;
        $params['q_email'] = $like;
    }
    if (!empty($filters['overdue_only'])) {
        $where[] = "br.status = 'fulfilled' AND EXISTS (
            SELECT 1 FROM borrowings bb WHERE bb.request_id = br.id AND bb.due_date < CURDATE()
        )";
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $statement = $pdo->prepare(
        'SELECT br.id, br.user_id, br.book_id, br.assigned_copy_id, br.reserved_copy_id,
                br.status, br.decision_note, br.reviewed_by, br.reviewed_at, br.created_at,
                b.title AS book_title, b.author AS book_author, u.full_name AS student_name, u.email AS student_email
          FROM borrow_requests br
          INNER JOIN books b ON b.id = br.book_id
          INNER JOIN users u ON u.id = br.user_id' . $whereSql .
        ' ORDER BY br.id DESC LIMIT :limit'
    );
    $statement->execute($params + ['limit' => $limit]);
    return (array) $statement->fetchAll();
}

/**
 * True when a student already has an active/pending request for this book.
 */
function catalogue_student_has_active_request(PDO $pdo, int $userId, int $bookId): bool
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM borrow_requests
          WHERE user_id = :user_id AND book_id = :book_id AND status IN ('pending', 'approved')
          LIMIT 1"
    );
    $statement->execute(['user_id' => $userId, 'book_id' => $bookId]);
    return $statement->fetch() !== false;
}

/**
 * Server-side eligibility check for a student submitting a request.
 *
 * Returns null when the request may be submitted; otherwise a short error code.
 */
function catalogue_student_can_request(PDO $pdo, int $userId, int $bookId): ?string
{
    $book = catalogue_book_get($pdo, $bookId);
    if ($book === null || (string) $book['status'] !== 'active') {
        return 'book_not_available';
    }

    if (catalogue_available_copies($pdo, $bookId) === []) {
        return 'no_available_copies';
    }

    if (!email_verification_is_verified($pdo, $userId)) {
        return 'email_not_verified';
    }

    $settings = get_settings($pdo);
    $activeLoanStmt = $pdo->prepare('SELECT COUNT(*) FROM borrowings WHERE user_id = :id AND status = \'active\'');
    $activeLoanStmt->execute(['id' => $userId]);
    $activeLoans = (int) $activeLoanStmt->fetchColumn();
    if ($activeLoans >= (int) $settings['max_active_borrowings']) {
        return 'max_active_borrowings';
    }

    if (catalogue_student_has_active_request($pdo, $userId, $bookId)) {
        return 'duplicate_active_request';
    }

    return null;
}

/**
 * Create a pending borrow request for a student.
 *
 * Uses the circulation service so the unique active-request key is enforced.
 *
 * @return int The new borrow_requests.id.
 */
function circulation_request_book(PDO $pdo, int $userId, int $bookId): int
{
    $settings = get_settings($pdo);
    $activeLoanStmt = $pdo->prepare('SELECT COUNT(*) FROM borrowings WHERE user_id = :id AND status = \'active\'');
    $activeLoanStmt->execute(['id' => $userId]);
    $activeLoans = (int) $activeLoanStmt->fetchColumn();
    if ($activeLoans >= (int) $settings['max_active_borrowings']) {
        throw new CirculationConflict('max_active_borrowings', 'You have reached the maximum number of active loans.');
    }

    $book = catalogue_book_get($pdo, $bookId);
    if ($book === null || (string) $book['status'] !== 'active' || catalogue_available_copies($pdo, $bookId) === []) {
        throw new CirculationConflict('book_not_available', 'The requested book is not currently available.');
    }

    if (!email_verification_is_verified($pdo, $userId)) {
        throw new CirculationConflict('email_not_verified', 'Please verify your email address before requesting books.');
    }

    if (catalogue_student_has_active_request($pdo, $userId, $bookId)) {
        throw new CirculationConflict('duplicate_active_request', 'You already have an active request for this title.');
    }

    $insert = $pdo->prepare(
        "INSERT INTO borrow_requests (user_id, book_id, status)
         VALUES (:user_id, :book_id, 'pending')"
    );
    $insert->execute(['user_id' => $userId, 'book_id' => $bookId]);

    $requestId = (int) $pdo->lastInsertId();
    if ($requestId === 0) {
        throw new CirculationConflict('request_insert_failed', 'Could not create the borrow request.');
    }
    return $requestId;
}

/**
 * Cancel/close a student request where permitted.
 */
function circulation_cancel_student_request(PDO $pdo, int $requestId, int $userId, int $actorId = 0): void
{
    $statement = $pdo->prepare('SELECT id, user_id, status FROM borrow_requests WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $requestId]);
    $request = $statement->fetch();
    if ($request === false) {
        throw new CirculationConflict('request_not_found', 'The requested borrow request does not exist.');
    }
    if ((int) $request['user_id'] !== (int) $userId) {
        throw new CirculationConflict('not_owner', 'You do not own this borrow request.');
    }
    if (!in_array($request['status'], ['pending', 'approved'], true)) {
        throw new CirculationConflict('request_not_closeable', 'This request can no longer be cancelled.');
    }

    circulation_close_request($pdo, (int) $request['id'], 'cancelled', $actorId);
}

/**
 * Save or create a catalogue category.
 *
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, id: int|null}
 */
function catalogue_save_category(PDO $pdo, array $input, ?int $id = null): array
{
    $errors = [];
    $name = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        $errors['name'] = 'Category name must be between 2 and 80 characters.';
    }
    if (mb_strlen($description) > 255) {
        $errors['description'] = 'Category description must not exceed 255 characters.';
    }

    if (!isset($errors['name'])) {
        $exists = $pdo->prepare('SELECT id FROM categories WHERE name = :name AND id <> :id');
        $exists->execute(['name' => $name, 'id' => (int) ($id ?? 0)]);
        if ($exists->fetch() !== false) {
            $errors['name'] = 'A category with that name already exists.';
        }
    }

    $newId = null;
    if ($errors === []) {
        try {
            if ($id === null) {
                $insert = $pdo->prepare('INSERT INTO categories (name, description) VALUES (:name, :description)');
                $insert->execute(['name' => $name, 'description' => $description === '' ? null : $description]);
                $newId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare('UPDATE categories SET name = :name, description = :description WHERE id = :id');
                $update->execute(['name' => $name, 'description' => $description === '' ? null : $description, 'id' => $id]);
                $newId = $id;
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors['name'] = 'A category with that name already exists.';
            } else {
                throw $exception;
            }
        }
    }

    return ['errors' => $errors, 'id' => $newId];
}

/**
 * Delete a category when no books reference it.
 */
function catalogue_delete_category_if_unused(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM books WHERE category_id = :id');
    $statement->execute(['id' => $id]);
    if ((int) $statement->fetchColumn() > 0) {
        return false;
    }
    $delete = $pdo->prepare('DELETE FROM categories WHERE id = :id');
    $delete->execute(['id' => $id]);
    return true;
}

/**
 * Save or create a catalogue book.
 *
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, id: int|null}
 */
function catalogue_save_book(PDO $pdo, array $input, ?int $id = null): array
{
    $errors = [];
    $title = trim((string) ($input['title'] ?? ''));
    $author = trim((string) ($input['author'] ?? ''));
    $isbn = trim((string) ($input['isbn'] ?? ''));
    $categoryId = filter_var($input['category_id'] ?? '', FILTER_VALIDATE_INT);
    $status = (string) ($input['status'] ?? 'active');
    $publicationYear = $input['publication_year'] === '' ? null : filter_var($input['publication_year'], FILTER_VALIDATE_INT);

    if (mb_strlen($title) < 2 || mb_strlen($title) > 255) {
        $errors['title'] = 'Book title must be between 2 and 255 characters.';
    }
    if (mb_strlen($author) < 2 || mb_strlen($author) > 200) {
        $errors['author'] = 'Author name must be between 2 and 200 characters.';
    }
    if ($isbn === '' && $id !== null) {
        // Editing may retain the existing ISBN below.
    }
    if (mb_strlen($isbn) > 20) {
        $errors['isbn'] = 'ISBN must be 20 characters or less.';
    }
    if ($categoryId === false || $categoryId < 1) {
        $errors['category_id'] = 'Choose a valid category.';
    }
    if (!in_array($status, ['active', 'archived'], true)) {
        $errors['status'] = 'Choose a valid status.';
    }
    if ($publicationYear !== null && ($publicationYear < 1000 || $publicationYear > 2100)) {
        $errors['publication_year'] = 'Publication year must be between 1000 and 2100.';
    }

    if (!isset($errors['isbn'])) {
        $exists = $pdo->prepare('SELECT id FROM books WHERE isbn = :isbn AND id <> :id');
        $exists->execute(['isbn' => $isbn, 'id' => (int) ($id ?? 0)]);
        if ($exists->fetch() !== false) {
            $errors['isbn'] = 'An ISBN is already registered.';
        }
    }

    $newId = null;
    if ($errors === []) {
        try {
            if ($id === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO books (category_id, isbn, title, author, publisher, publication_year, description, status)
                     VALUES (:category_id, :isbn, :title, :author, :publisher, :publication_year, :description, :status)'
                );
                $insert->execute([
                    'category_id' => (int) $categoryId,
                    'isbn' => $isbn,
                    'title' => $title,
                    'author' => $author,
                    'publisher' => trim((string) ($input['publisher'] ?? '')),
                    'publication_year' => $publicationYear,
                    'description' => trim((string) ($input['description'] ?? '')),
                    'status' => $status,
                ]);
                $newId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare(
                    'UPDATE books SET category_id = :category_id, isbn = :isbn, title = :title,
                            author = :author, publisher = :publisher, publication_year = :publication_year,
                            description = :description, status = :status
                      WHERE id = :id'
                );
                $update->execute([
                    'category_id' => (int) $categoryId,
                    'isbn' => $isbn,
                    'title' => $title,
                    'author' => $author,
                    'publisher' => trim((string) ($input['publisher'] ?? '')),
                    'publication_year' => $publicationYear,
                    'description' => trim((string) ($input['description'] ?? '')),
                    'status' => $status,
                    'id' => $id,
                ]);
                $newId = $id;
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors['isbn'] = 'An ISBN is already registered.';
            } else {
                throw $exception;
            }
        }
    }

    return ['errors' => $errors, 'id' => $newId];
}

/**
 * Save or create a physical copy.
 *
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, id: int|null}
 */
function catalogue_save_copy(PDO $pdo, array $input, ?int $id = null): array
{
    $errors = [];
    $bookId = filter_var($input['book_id'] ?? '', FILTER_VALIDATE_INT);
    $copyCode = trim((string) ($input['copy_code'] ?? ''));
    $status = (string) ($input['status'] ?? 'available');
    $shelfLocation = trim((string) ($input['shelf_location'] ?? ''));
    $conditionNote = trim((string) ($input['condition_note'] ?? ''));

    if ($bookId === false || $bookId < 1) {
        $errors['book_id'] = 'Choose a valid book.';
    }
    if (mb_strlen($copyCode) < 1 || mb_strlen($copyCode) > 40) {
        $errors['copy_code'] = 'Copy identifier is required and must be 40 characters or less.';
    }
    if (!in_array($status, catalogue_copy_status_allowed(), true)) {
        $errors['status'] = 'Choose a valid copy status.';
    }
    if (mb_strlen($shelfLocation) > 40) {
        $errors['shelf_location'] = 'Shelf location must not exceed 40 characters.';
    }
    if (mb_strlen($conditionNote) > 255) {
        $errors['condition_note'] = 'Condition note must not exceed 255 characters.';
    }

    if (!isset($errors['copy_code'])) {
        $exists = $pdo->prepare('SELECT id FROM book_copies WHERE copy_code = :code AND id <> :id');
        $exists->execute(['code' => $copyCode, 'id' => (int) ($id ?? 0)]);
        if ($exists->fetch() !== false) {
            $errors['copy_code'] = 'A copy with that identifier already exists.';
        }
    }

    // Do not let a status edit free or reassign a copy that the circulation
    // state machine is currently using. This mirrors circulation_rule_f.
    if ($id !== null && $errors === []) {
        $current = $pdo->prepare('SELECT status FROM book_copies WHERE id = :id');
        $current->execute(['id' => $id]);
        $currentStatus = $current->fetchColumn();
        if ($currentStatus !== false && (string) $currentStatus !== $status) {
            $activeRequest = $pdo->prepare(
                "SELECT 1 FROM borrow_requests
                  WHERE (reserved_copy_id = :id OR assigned_copy_id = :id)
                    AND status = 'approved' LIMIT 1"
            );
            $activeRequest->execute(['id' => $id]);
            $activeLoan = $pdo->prepare(
                "SELECT 1 FROM borrowings
                  WHERE status = 'active'
                    AND (active_copy_id = :id OR book_copy_id = :id) LIMIT 1"
            );
            $activeLoan->execute(['id' => $id]);
            if ($activeRequest->fetch() !== false || $activeLoan->fetch() !== false) {
                $errors['status'] = 'This copy is reserved or on loan; its status cannot be changed here.';
            }
        }
    }

    $newId = null;
    if ($errors === []) {
        try {
            if ($id === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO book_copies (book_id, copy_code, status, shelf_location, condition_note)
                     VALUES (:book_id, :copy_code, :status, :shelf_location, :condition_note)'
                );
                $insert->execute([
                    'book_id' => (int) $bookId,
                    'copy_code' => $copyCode,
                    'status' => $status,
                    'shelf_location' => $shelfLocation === '' ? null : $shelfLocation,
                    'condition_note' => $conditionNote === '' ? null : $conditionNote,
                ]);
                $newId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare(
                    'UPDATE book_copies SET copy_code = :copy_code, status = :status,
                            shelf_location = :shelf_location, condition_note = :condition_note
                      WHERE id = :id'
                );
                $update->execute([
                    'copy_code' => $copyCode,
                    'status' => $status,
                    'shelf_location' => $shelfLocation === '' ? null : $shelfLocation,
                    'condition_note' => $conditionNote === '' ? null : $conditionNote,
                    'id' => $id,
                ]);
                $newId = $id;
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors['copy_code'] = 'A copy with that identifier already exists.';
            } else {
                throw $exception;
            }
        }
    }

    return ['errors' => $errors, 'id' => $newId];
}

/**
 * Render a book cover thumbnail. Only local uploads/covers paths are accepted;
 * missing covers render an empty placeholder so the layout stays consistent.
 */
function catalogue_availability_label(int $count): string
{
    return $count === 0 ? 'Zero' : (string) $count;
}

function catalogue_cover_markup(array $book, bool $large = false): string
{
    $cover = (string) ($book['cover_image'] ?? '');
    $class = 'book-cover' . ($large ? ' book-cover--lg' : '');
    if ($cover === '' || strpos($cover, 'uploads/covers/') !== 0) {
        return '<span class="' . $class . ' book-cover--empty" aria-hidden="true"></span>';
    }
    return '<img class="' . $class . '" src="' . e(url($cover)) . '" alt="" loading="lazy">';
}
