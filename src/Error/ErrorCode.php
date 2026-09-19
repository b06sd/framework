<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * Stable, machine-readable codes for framework-level errors. Applications can build logic around
 * these instead of parsing messages, and are free to use their own string codes (`CUSTOMER_NOT_FOUND`)
 * from their own exceptions.
 *
 * @api
 */
enum ErrorCode: string
{
    case InternalError = 'INTERNAL_ERROR';
    case BadRequest = 'BAD_REQUEST';
    case ValidationFailed = 'VALIDATION_FAILED';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case AccessDenied = 'ACCESS_DENIED';
    case CsrfTokenInvalid = 'CSRF_TOKEN_INVALID';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case NotFound = 'NOT_FOUND';
    case RouteNotFound = 'ROUTE_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';
    case DependencyNotFound = 'DEPENDENCY_NOT_FOUND';
    case ConfigurationInvalid = 'CONFIGURATION_INVALID';
    case CompilationFailed = 'COMPILATION_FAILED';
    case TemplateError = 'TEMPLATE_ERROR';
    case DatabaseError = 'DATABASE_ERROR';
    case PayloadInvalid = 'PAYLOAD_INVALID';
    case JobFailed = 'JOB_FAILED';
}
