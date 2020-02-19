# WordPress Plugin Builder

## Requirements

### Composer

https://getcomposer.org/download/

### Phing

```shell script
$ composer global require phing/phing
```

## Installation

```shell script
$ composer global require alledia/wordpress-plugin-builder
```

Set a new environment variable called ALLEDIA_BUILDER_PATH setting the correct full path to the builder from the global composer vendor directory.
Add the following code to the .zshrc, .bashrc or .bash_profile file: 

```shell script
$ export ALLEDIA_BUILDER_PATH=/Users/<username>/.composer/vendor/alledia/wordpress-plugin-builder
```

If you use a *unix or BSD based system, you probably have to run the following command to reload the bash script file and make the variable you just create available to your terminal session:

```shell script
$ source ~/.bashrc
```  

## Using

Before building the package for the first time, or whenever it is needed, you have to run "composer update" to download/update the required dependencies:

```shell script
$ composer update --no-dev
```  

For setting the new version number you can run the command:

```shell script
$ phing set-version
```

Then you can type the new version number and press Enter.

For creating the package, you run:

```shell script
$ phing build
```

The package will be added to a `dist` folder in the project root dir. For automatically moving any build package to a common directory you can set the environment variable `PS_GLOBAL_PACKAGES_PATH` adding the following line to your .zshrc, .bashrc or .bash_profile file:

```shell script
export PS_GLOBAL_PACKAGES_PATH="/Users/<username>/Dropbox/Tmp-Packages"
```