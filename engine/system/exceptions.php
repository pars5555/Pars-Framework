<?php

namespace system {

    class SysExceptions {

        public static function fileNotFound($filePath) {
            throw new \Exception('File not found: ' . $filePath, 10040);
        }

        public static function routeNotFound($route) {
            throw new \Exception('No route found with: ' . $route, 10020);
        }
        
        public static function defaultRouteNotFound() {
            throw new \Exception('No default route found. Please <sysdefault> route in routings.js file', 10020);
        }

        public static function modelAttributeNotFound($route) {
            throw new \Exception('No <model> attibute found in the route: ' . $route, 10021);
        }
        
        public static function loggerIsNotEnabled() {
            throw new \Exception('Logger is not enabled for this request. Please enable logger by overriding <log()> method in your request.', 10021);
        }

    }

}