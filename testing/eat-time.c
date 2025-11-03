#include <stdio.h>
#include <stdlib.h>
#include <time.h>

int main(int argc, char *argv[]) {
    if (argc != 2) {
        fprintf(stderr, "Usage: %s <seconds>\n", argv[0]);
        return 1;
    }

    double seconds = atof(argv[1]);
    clock_t start_time = clock();
    double clocks_to_run = seconds * CLOCKS_PER_SEC;

    printf("Eating CPU time for %.2f seconds...\n", seconds);

    while (clock() - start_time < clocks_to_run) {
        // Busy-wait doing some math
        double result = 0;
        for (int i = 0; i < 1000; i++) {
            result += i * 3.14;
        }
    }

    printf("Done.\n");

    return 0;
}

