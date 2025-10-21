# Procurement and Inventory System

## Overview
The Procurement and Inventory System is designed to streamline the management of inventory and procurement processes across multiple branches. It provides functionalities for custodians to manage inventory items, check their status, and send purchase requests to procurement managers. Procurement managers can track requests and manage inventory needs effectively.

## Features
- **User Roles**: Two user roles - Custodian and Procurement Manager.
- **Inventory Management**: Custodians can manage inventory items, including adding new items, updating their status, and generating reports.
- **Purchase Requests**: Custodians can send purchase requests for items that need repair or replacement.
- **Dashboard**: Each user role has a dedicated dashboard to view relevant information and pending requests.
- **PDF Generation**: The system can generate PDF files for purchase requests and inventory reports.
- **Branch Management**: Supports multiple branches including Manila, Sto Tomas Batangas, San Fernando City La Union, Dasmariñas City Cavite, and Quezon City.

## Installation
1. Clone the repository:
   ```
   git clone <repository-url>
   ```
2. Navigate to the project directory:
   ```
   cd procurement-inventory-system
   ```
3. Install dependencies using Composer:
   ```
   composer install
   ```
4. Copy `.env.example` to `.env` and configure the database credentials. When deploying to Heroku, the `DATABASE_URL` variable is populated automatically.

5. Apply the PostgreSQL schema:
   ```
   psql "$DATABASE_URL" -f database/schema.sql
   ```
   The script provisions:
   - audited entities (`inventory_items`, `purchase_requests`, `users`)
   - custom user IDs that follow the `YYYYNNNN` pattern (e.g. `20250001`)
   - login and change history tables for traceability

## Usage
- Access the application via the `public/index.php` file.
- Log in with a provisioned account (`users` table). Passwords are stored with `password_hash()` and must be created through the `AuthService` (or any provisioning script that uses the same hashing function).
- Custodians can manage inventory and send purchase requests.
- Procurement Managers can review and manage purchase requests from custodians, including full status history.

## Database Notes
- The connection layer automatically parses the Heroku `DATABASE_URL` and enforces SSL by default.
- Every business table captures `created_at / created_by` and `updated_at / updated_by`. Changes are mirrored into `audit_logs` for accountability.
- User logins and logouts are recorded in `auth_activity`, along with IP address and user agent.
- Inventory movements are tracked through `inventory_movements` whenever quantities change.

## Directory Structure
- `public/`: Contains the entry point and frontend assets.
- `src/`: Contains the application logic, including controllers, models, and services.
- `storage/`: Used for storing generated PDF files.
- `templates/`: Contains the views for the application.
- `tests/`: Contains feature tests for the application.

## Contributing
Contributions are welcome! Please submit a pull request or open an issue for any enhancements or bug fixes.

## License
This project is licensed under the MIT License. See the LICENSE file for more details.