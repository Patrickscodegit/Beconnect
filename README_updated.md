# Vehicle Data Extraction System

A comprehensive Laravel 11 application for vehicle and shipping data extraction with S3-compatible storage (MinIO).

## Features

- **Vehicle Data Management**: Import and manage vehicle specifications from CSV files
- **VIN WMI Database**: Comprehensive World Manufacturer Identifier database
- **S3-Compatible Storage**: MinIO integration for scalable file storage
- **Search Functionality**: Laravel Scout with database driver for fast searches
- **Authentication**: Laravel Breeze for user management
- **Queue Processing**: Laravel Horizon for background job management
- **Testing**: Laravel Pest for comprehensive testing
- **Code Quality**: Laravel Pint for consistent code formatting

## Technology Stack

- **Framework**: Laravel 11 (PHP 8.3)
- **Database**: PostgreSQL
- **Storage**: MinIO (S3-compatible)
- **Authentication**: Laravel Breeze
- **Search**: Laravel Scout (Database driver)
- **Queues**: Laravel Horizon (Redis)
- **Testing**: Laravel Pest
- **Code Style**: Laravel Pint

## Installation

### Prerequisites

- PHP 8.3+
- Composer
- PostgreSQL
- Redis
- MinIO

### Setup Instructions

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd Bconnect
   ```

2. **Install dependencies**:
   ```bash
   composer install
   npm install && npm run build
   ```

3. **Environment configuration**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database setup**:
   ```bash
   # Create PostgreSQL database
   createdb bconnect_db
   
   # Configure .env database settings
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=bconnect_db
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

5. **MinIO setup**:
   ```bash
   # Install MinIO
   brew install minio/stable/minio minio/stable/mc
   
   # Start MinIO server
   MINIO_ROOT_USER=minio MINIO_ROOT_PASSWORD=minio123 minio server ~/minio-data --console-address ":9001" &
   
   # Configure MinIO client
   mc alias set local http://127.0.0.1:9000 minio minio123
   mc mb local/vehicle-data
   ```

6. **Environment configuration for MinIO**:
   ```env
   # MinIO Configuration
   AWS_ACCESS_KEY_ID=minio
   AWS_SECRET_ACCESS_KEY=minio123
   AWS_DEFAULT_REGION=eu-west-1
   AWS_BUCKET=vehicle-data
   AWS_USE_PATH_STYLE_ENDPOINT=true
   AWS_ENDPOINT=http://127.0.0.1:9000
   ```

7. **Run migrations and seeders**:
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

8. **Test MinIO connection**:
   ```bash
   php artisan minio:test
   ```

## Usage

### Starting the Application

1. **Start the Laravel development server**:
   ```bash
   php artisan serve
   ```

2. **Start queue processing** (optional):
   ```bash
   php artisan horizon
   ```

3. **Access the application**:
   - Application: http://127.0.0.1:8000
   - MinIO Console: http://127.0.0.1:9001 (minio/minio123)
   - Horizon Dashboard: http://127.0.0.1:8000/horizon

### File Management

The application provides comprehensive file management through the web interface:

- **CSV Data Import**: Upload CSV files for bulk data import
- **Vehicle Images**: Upload and organize vehicle photos by VIN
- **Vehicle Documents**: Store manuals, specifications, and related documents

### Storage Structure

Files are organized in MinIO with the following structure:
```
vehicle-data/
├── csv-imports/          # CSV data files
├── vehicle-images/       # Vehicle photos organized by WMI
│   ├── 1HG/             # Honda vehicles
│   ├── JHM/             # Acura vehicles
│   └── ...
└── vehicle-documents/    # Manuals and specifications
    ├── 1HG/             # Honda documents
    ├── JHM/             # Acura documents
    └── ...
```

## API Endpoints

### File Upload Endpoints (Authenticated)
- `POST /upload/csv` - Upload CSV files
- `POST /upload/vehicle-image` - Upload vehicle images
- `POST /upload/vehicle-document` - Upload vehicle documents
- `GET /files` - List stored files
- `DELETE /files` - Delete files

## Services

### StorageService
Centralized file operations with support for:
- File upload with automatic naming
- Temporary URL generation
- Directory operations
- File metadata retrieval

### UnitConverter
Convert between metric and US customary units:
- Length conversions (meters ↔ feet/inches)
- Weight conversions (kg ↔ lbs)
- Volume conversions (liters ↔ gallons)

## Testing

### Run Tests
```bash
# Test MinIO integration
php artisan minio:test

# Run all tests
php artisan test

# Run specific test suites
php artisan test tests/Unit/
php artisan test tests/Feature/
```

## Code Quality

```bash
./vendor/bin/pint
```

## Production Deployment

### Environment Variables
For production deployment with AWS S3, update these variables:
```env
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-production-bucket
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_ENDPOINT=
```

### MinIO to AWS S3 Migration
The application is designed to seamlessly switch between MinIO (development) and AWS S3 (production) by updating environment variables.

## MinIO Integration Complete ✅

The Laravel 11 vehicle data extraction system now includes:

- ✅ **MinIO Server**: Running on http://127.0.0.1:9000
- ✅ **MinIO Console**: Available at http://127.0.0.1:9001
- ✅ **S3 Compatibility**: Full Laravel filesystem integration
- ✅ **File Management**: Web interface for uploads
- ✅ **Storage Service**: Centralized file operations
- ✅ **Connection Verified**: All tests passing

The system is production-ready and can switch between MinIO (development) and AWS S3 (production) by updating environment variables.
