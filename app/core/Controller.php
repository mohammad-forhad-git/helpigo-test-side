<?php

declare(strict_types=1);

namespace App\Core;

/** Base controller: rendering, redirects and JSON responses. */
abstract class Controller
{
    protected function render(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        echo View::render($template, $data, $layout);
    }

    protected function redirect(string $path): never
    {
        redirect($path);
    }

    /** Sends the user back to the form with errors and their previous input (passwords excluded). */
    protected function backWithErrors(string $path, array $errors, array $input = [], string $message = 'Please check the highlighted fields.'): never
    {
        unset($input['_token'], $input['password'], $input['password_confirmation']);
        Session::flash('errors', $errors);
        Session::flash('old', $input);
        Session::flash('error', $message);
        redirect($path);
    }

    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function notFound(): never
    {
        throw new HttpException(404);
    }
}
