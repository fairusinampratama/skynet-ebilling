# Running the App

This application is containerized using [Laravel Sail](https://laravel.com/docs/sail). Laravel Sail provides a Docker-based development environment that includes PHP, MySQL, Redis, and other essential services required to run the application without needing to install them directly on your host machine.

## Prerequisites

Before you start, ensure you have the following installed on your machine:
- [Docker](https://www.docker.com/products/docker-desktop/) (and Docker Compose)

## Setup and Running

Follow these steps to get the application running on your local machine:

### 1. Copy the Environment File
If you haven't already, copy the example environment file and configure it:
```bash
cp .env.example .env
```
*(Note: The default `.env.example` settings are already configured to work seamlessly with Sail.)*

### 2. Start the Docker Containers
To start all the necessary Docker containers in the background, run:
```bash
./vendor/bin/sail up -d
```
*(Tip: You can create a bash alias for `sail` so you don't have to type the full path: `alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'`)*

### 3. Install PHP Dependencies
If you haven't installed the composer dependencies yet, run:
```bash
./vendor/bin/sail composer install
```

### 4. Run Migrations
Initialize the database with the required tables:
```bash
./vendor/bin/sail artisan migrate
```
*(You may want to add `--seed` if there are database seeders available: `./vendor/bin/sail artisan migrate --seed`)*

### 5. Install Node Dependencies
Install the required frontend packages:
```bash
./vendor/bin/sail npm install
```

### 6. Start the Vite Development Server
To compile assets and start the frontend development server, run:
```bash
./vendor/bin/sail npm run dev
```

## Accessing the Application

Once the containers and Vite server are running, you can access the application in your browser at:

- **Main Application**: [http://localhost](http://localhost)

## Stopping the Application

When you're done working, you can safely stop and remove the containers by running:
```bash
./vendor/bin/sail down
```
