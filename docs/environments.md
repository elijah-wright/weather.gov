# Environments

There are two tiers of environments - unstable and stable. 

## Unstable

### Local/Development

See [dev docs](dev/) for information on running the application locally. We use docker with composer to manage the application locally.

### Developer Sandboxes/QA

These are developer specific spaces that are not assumed to be stable. They are used for sharing feature work with designers and previewing work that will merge into main. 

Deployments can be made with the Github Action. 

## Stable

### Staging

Staging is a "pre-production" environment for QA and is considered "golden" to what is currently on Github.

Staging gets deploys on every push to main. These are done automatically through the Github Action.

### Beta

Beta is a "production" environment that only gets deploys when we do manual tagged, scheduled releases as a product team. 