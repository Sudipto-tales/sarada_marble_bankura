import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("segment_image", Path(__file__).parents[1] / "segment_image.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class PromptTests(unittest.TestCase):
    def setUp(self):
        self.data = {"version": 1, "revision": 1, "surfaces": [{"id": "floor", "label": "floor", "points": [{"xy": [0.4, 0.8], "label": 1}]}]}

    def test_valid_click_and_box(self):
        runner.validate_prompts(self.data)
        self.data["surfaces"][0].update(points=[], box=[0.1, 0.2, 0.8, 0.9])
        runner.validate_prompts(self.data)

    def test_invalid_prompts(self):
        for xy in [[float("nan"), 0.2], [1.1, 0.5], [True, 0.4], [0.2]]:
            with self.subTest(xy=xy), self.assertRaises(ValueError):
                data = copy.deepcopy(self.data)
                data["surfaces"][0]["points"][0]["xy"] = xy
                runner.validate_prompts(data)
        self.data["surfaces"][0]["points"][0]["label"] = 0
        with self.assertRaises(ValueError):
            runner.validate_prompts(self.data)

    def test_crossed_corners_and_duplicate_surfaces(self):
        data = copy.deepcopy(self.data)
        data["surfaces"][0]["corners"] = [[0, 0], [1, 1], [1, 0], [0, 1]]
        with self.assertRaises(ValueError):
            runner.validate_prompts(data)
        self.data["surfaces"].append(copy.deepcopy(self.data["surfaces"][0]))
        with self.assertRaises(ValueError):
            runner.validate_prompts(self.data)


if __name__ == "__main__":
    unittest.main()
