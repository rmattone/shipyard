# PostgreSQL schemas

Open the server's PostgreSQL connection, then **Databases → Schemas** next to the
existing database. The dialog lists non-system schemas and their owners, including
`public`. A schema belongs inside a database; **Create Database** creates a different
database and does not provision a schema.

Organization owners and administrators can create a schema. Enter its name and
select the existing PostgreSQL application user as **Owner**. If that user is not
listed, create it first in **Users**. Members can inspect the schema list but cannot
create schemas.

For the DSC/Simcha deployment, open **dsc → Schemas**, enter `ticketing`, and select
the role from the API's `DB_USERNAME`. After creation, retry the application's
deployment. No changes to Shipyard environment variables or local database migrations
are required to install this feature; deploy both its backend and frontend.

Shipyard connects over SSH and executes `CREATE SCHEMA ... AUTHORIZATION ...`
using the existing database connection's stored administrative credentials. Those
credentials must be allowed to create schemas in the selected database and assign
the chosen owner. The application user is given ownership of this schema, without
granting database-wide CREATE or superuser rights. The owner can create its own
tables and run migrations there; normal database CONNECT access must already exist.

Creation fails visibly if the schema already exists, the owner does not exist,
or the administrative connection lacks permission. It never silently changes an
existing schema's owner or permissions. Existing tables created by a different role
may require a separate ownership review; creating a schema does not modify them.
System schemas and databases cannot be provisioned through this form. MySQL does
not show schema controls.

Validation: HTTP tests cover organization/role restrictions, parent-server binding,
identifier validation, error responses and SSH command quoting. Remote PostgreSQL
commands use the selected database and the saved administrative connection. No
production database is contacted by automated tests.
