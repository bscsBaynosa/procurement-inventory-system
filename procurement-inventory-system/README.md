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
4. Configure your environment variables by copying the `.env.example` file to `.env` and updating the necessary settings.

## Usage
- Access the application via the `public/index.php` file.
- Choose to log in as either a Custodian or Procurement Manager.
- Custodians can manage inventory and send purchase requests.
- Procurement Managers can review and manage purchase requests from custodians.

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