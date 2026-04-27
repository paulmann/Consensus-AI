# Consensus-AI Architecture

## Overview

Consensus-AI is a PHP 8.4 web application implementing an OpenCorum-style AI council for deliberative intelligence. It follows a clean Domain-Driven Design (DDD) architecture with clear separation of concerns.

## Technology Stack

- **PHP**: 8.4+ (strict types enabled)
- **Database**: MySQL 8.x via PDO
- **Autoloading**: PSR-4 via Composer
- **Session Management**: Native PHP sessions
- **Environment**: dotenv-style configuration

## Architecture Layers

### Domain Layer (`src/Domain/`)

The domain layer contains pure business logic with no dependencies on infrastructure or framework code.

- **Entities**: `MemoryItem`, `Argument`, `Session`, `Participant`, `User`, `Topic`
- **Value Objects**: Enums for statuses, types, and roles
- **Repository Interfaces**: Contracts for data access (e.g., `MemoryRepository`, `ArgumentRepository`)

### Application Layer (`src/Application/`)

The application layer orchestrates domain operations and implements use cases.

- **Services**: Business service classes (e.g., `MemoryService`, `ArgumentService`)
- **DTOs**: Data Transfer Objects for cross-layer communication
- **Commands/Queries**: CQRS-inspired patterns for complex operations

### Infrastructure Layer (`src/Infrastructure/`)

The infrastructure layer provides concrete implementations of domain interfaces.

- **Database**: PDO connections, SQL schema, repository implementations
- **HTTP**: Router, controllers, middleware
- **View**: Template engine, layout management
- **External APIs**: AI model integrations (future)

### Presentation Layer (`public/`)

The presentation layer handles HTTP requests and renders responses.

- **Entry Point**: `public/index.php`
- **Assets**: CSS, JavaScript, images
- **Templates**: PHP/HTML view templates

## Key Design Patterns

### Repository Pattern

Each domain entity has a corresponding repository interface in the Domain layer and a MySQL implementation in the Infrastructure layer:

```
Domain: MemoryRepository (interface)
Infrastructure: MySqlMemoryRepository (implementation)
```

### Singleton Pattern

The `DatabaseConnection` class uses the singleton pattern to ensure a single PDO connection per request:

```php
$connection = DatabaseConnection::getInstance();
$pdo = $connection->getPdo();
```

### Service Layer Pattern

Application services coordinate between domain entities and repositories:

```php
$memoryService = new MemoryService($memoryRepository);
$items = $memoryService->getMemoriesBySession($sessionId);
```

### Dependency Injection

Services receive their dependencies via constructor injection, making them easily testable:

```php
public function __construct(
    private readonly MemoryRepository $repository
) {}
```

## Database Schema

The application uses the following tables:

1. **users** - User accounts and authentication
2. **topics** - Discussion topics and categories
3. **sessions** - AI council sessions
4. **participants** - Session participants (linked to users)
5. **arguments** - Claims, evidence, and counter-arguments
6. **memory_items** - Vector memory storage for AI context
7. **session_topics** - Junction table for session-topic relationships

All tables use:
- `INT UNSIGNED AUTO_INCREMENT` primary keys
- `TIMESTAMP` columns with `ON UPDATE CURRENT_TIMESTAMP`
- `utf8mb4_unicode_ci` collation
- Appropriate indexes for query optimization
- Foreign key constraints with `ON DELETE CASCADE` or `ON DELETE SET NULL`

## Request Flow

1. **Entry Point**: `public/index.php` receives the HTTP request
2. **Environment Setup**: Load `.env` configuration, start session
3. **Database Connection**: `DatabaseConnection::getInstance()`
4. **Routing**: `Router->dispatch()` matches URI and method to controller
5. **Controller**: Handles request, calls appropriate service
6. **Service**: Orchestrates domain logic and repository calls
7. **Repository**: Executes SQL queries via PDO
8. **Response**: Controller renders template or JSON response

## Security Considerations

- **SQL Injection Prevention**: All queries use prepared statements with PDO
- **Input Validation**: Strict types and type hints throughout
- **Session Security**: Configurable secure flags via `.env`
- **Error Handling**: Production errors are hidden; debug mode via `.env`
- **Password Hashing**: Uses PHP's `password_hash()` with bcrypt

## Performance Optimizations

- **Database**: Buffered queries, proper indexes, foreign key optimization
- **Memory**: Singleton database connection, configurable memory limits
- **Caching**: File-based caching with configurable driver
- **Rate Limiting**: Request throttling with configurable windows

## Future Extensions

- **AI Integration**: BotHub.chat API for multi-model consensus
- **Vector Search**: Embedding-based memory retrieval
- **WebSocket**: Real-time session updates
- **OAuth**: Social login integration
- **API**: RESTful API for external integrations

## Project Structure

```
Consensus-AI/
├── docs/
├── public/
│   ├── index.php
│   └── assets/
├── src/
│   ├── Application/
│   │   └── Memory/
│   │       └── MemoryService.php
│   ├── Domain/
│   │   └── Memory/
│   │       ├── MemoryItem.php
│   │       └── MemoryRepository.php
│   └── Infrastructure/
│       └── Database/
│           ├── DatabaseConnection.php
│           ├── DatabaseTables.php
│           └── Memory/
│               └── MySqlMemoryRepository.php
├── composer.json
├── .env.example
└── README.md
```
