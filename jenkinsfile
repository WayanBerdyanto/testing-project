pipeline {
    agent any
    stages {
        // Add stage for auto pull
        stage('Auto Pull') {
            steps {
                sh 'git pull origin main'
            }
        }
    }
}
