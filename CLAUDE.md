# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Frontend Development
```bash
# Start development server with hot reload
npm run start

# Build for production
npm run build

# Linting and formatting
npm run lint:js
npm run lint:css
npm run format
```

### PHP Development
```bash
# Run PHP tests with PestPHP
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/Indexables/Post/QueryBuilder/BasicQueryTest.php

# Run PHPUnit (alternative)
vendor/bin/phpunit

# Run PHPStan static analysis
ddev exec --dir /var/www/html/public/content/plugins/meiliscout composer test:types

# Run all tests (lint + types + unit)
composer test
```

### DDEV Environment
When working in the DDEV environment, prefix commands with:
```bash
ddev exec --dir /var/www/html/public/content/plugins/meiliscout [command]
```

Example:
```bash
ddev exec --dir /var/www/html/public/content/plugins/meiliscout composer test:types
```

### Build System
- Uses WordPress Scripts (@wordpress/scripts) for modern build pipeline
- Webpack configuration extends WordPress defaults with custom entries
- TailwindCSS integration via PostCSS
- Automatic block.json and PHP file copying for Gutenberg blocks

## Architecture Overview

### Core Framework
**MeiliScout** is a modern WordPress plugin that integrates Meilisearch with a sophisticated, modular architecture:

- **Service Container**: Custom PSR-11 compliant dependency injection container
- **Service Provider Pattern**: Modular service registration system in `src/Providers/`
- **Domain-Driven Design**: Business logic organized in `src/Domain/`
- **Query Builder Pattern**: Fluent interface for building Meilisearch queries

### Key Components

#### Foundation Layer (`src/Foundation/`)
- `Application.php`: Main bootstrapper that registers all service providers
- `Container.php`: PSR-11 dependency injection container with singleton support
- Entry point bootstraps through service providers defined in `Application::$providers`

#### Query System (`src/Query/`)
- `MeiliQueryBuilder`: Main query builder with specialized sub-builders
- `QueryIntegration`: Integrates with WordPress query system
- `WPQueryAdapter`: Adapts WordPress queries to Meilisearch format
- Multiple specialized builders: `MetaQueryBuilder`, `TaxQueryBuilder`, `SearchQueryBuilder`, etc.

#### Indexables (`src/Indexables/`)
- `PostIndexable`: WordPress post indexing implementation
- `TaxonomyIndexable`: Taxonomy indexing implementation
- Implements `Indexable` contract for extensibility

### Frontend Architecture

#### AlpineJS Integration
- **Reactive Components**: Uses AlpineJS for modern reactive UI
- **Store Pattern**: Centralized state management with `MeiliscoutStore`
- **Custom Prefix**: `x-meiliscout-` prefix to avoid conflicts
- **Component Hierarchy**: `BaseFacet` → specialized facet types

#### Facet System
Located in `resources/assets/js/frontend/components/facets/`:
- `BaseFacet`: Base functionality for all facets
- Specialized facets: `ButtonFacet`, `CheckboxFacet`, `RadioFacet`, `RangeFacet`, `SelectFacet`
- `FacetFactory`: Factory pattern for creating appropriate facet instances
- `FacetManager`: Coordinates facet interactions

#### Gutenberg Blocks
- **Custom Blocks**: `query-loop` and `query-loop-facet` blocks
- **React Components**: Modern React/JSX components in `resources/assets/js/gutenberg/`
- **Server-Side Rendering**: PHP render functions for blocks

### Configuration
- **Config System**: `src/Config/Config.php` and `src/Config/Settings.php`
- **Plugin Constants**: Defined in `plugin.php`
- **Default Index**: Configured in `config/meiliscout.php`

## Development Conventions

### PHP Standards
- **PHP 8.2+**: Modern PHP with strict typing (`declare(strict_types=1)`)
- **PSR Standards**: PSR-4 autoloading, PSR-11 container interface
- **Namespace Structure**: `Pollora\MeiliScout\` follows directory structure
- **Documentation**: Comprehensive PHPDoc comments required

### Code Organization
- **Contracts**: Interfaces in `src/Contracts/` for extensibility
- **Enums**: Domain enums in `src/Domain/Search/Enums/`
- **Validators**: Type validation in `src/Domain/Search/Validators/`
- **Service Providers**: Modular service registration pattern

### Frontend Standards
- **Component-Based**: Reusable Alpine.js components
- **Asset Structure**: Separate CSS/JS for admin, editor, and frontend
- **Build Process**: Webpack with WordPress Scripts integration
- **Modern CSS**: TailwindCSS with PostCSS processing

## Testing Strategy

### Test Structure
- **PestPHP**: Modern PHP testing framework
- **Unit Tests**: Individual component testing
- **Query Builder Tests**: Comprehensive search functionality testing
- **Mock Objects**: WordPress function mocking for isolated testing

### Test Organization
- Tests mirror `src/` structure
- Mock WordPress functions in `tests/Unit/Indexables/Post/QueryBuilder/MockWPQuery.php`
- Custom `TestCase` base class for shared functionality

## Key File Locations

### Core Files
- `plugin.php`: Plugin entry point
- `src/Foundation/Application.php`: Main application bootstrapper
- `src/Foundation/Container.php`: Dependency injection container

### Query System
- `src/Query/MeiliQueryBuilder.php`: Main query builder
- `src/Query/QueryIntegration.php`: WordPress integration
- `src/Query/Builders/`: Specialized query builders

### Frontend
- `resources/assets/js/frontend/stores/MeiliscoutStore.js`: Alpine.js store
- `resources/assets/js/frontend/components/facets/`: Facet components
- `resources/assets/js/gutenberg/blocks/`: Gutenberg block components

### Configuration
- `config/meiliscout.php`: Plugin configuration
- `webpack.config.js`: Build configuration
- `composer.json`: PHP dependencies and autoloading
